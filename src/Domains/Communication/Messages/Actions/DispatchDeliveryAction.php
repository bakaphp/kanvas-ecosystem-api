<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\Recipient;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\EligibilityResolver;
use Kanvas\Communication\Support\TransportManager;
use Throwable;

/**
 * The one place a provider is called for an outbound message. Everything that could have changed
 * while the delivery sat in the queue — the connection, its switch, the recipient's consent — is
 * read again here, because the answers from when it was queued are no longer answers.
 */
final class DispatchDeliveryAction
{
    public function __construct(
        private readonly Delivery $delivery
    ) {
    }

    public function execute(): Delivery
    {
        if (! $this->claim()) {
            return $this->delivery;
        }

        $this->delivery->refresh();
        $connection = $this->delivery->connection;

        $staleReason = match (true) {
            $connection === null => 'no_connection',
            $connection->unavailableReason() !== null => $connection->unavailableReason(),
            // Queued before the connection was switched off and on again: the switch-off cancelled
            // this work, and switching back on must not quietly release it.
            $connection->activation_version !== $this->delivery->activation_version => 'stale_activation',
            default => null,
        };

        if ($staleReason !== null) {
            $this->delivery->finishWithoutSending(DeliveryStatusEnum::SKIPPED, $staleReason);

            return $this->delivery;
        }

        $request = $this->delivery->request;
        $recipient = Recipient::inConversation($request->people, (string) $this->delivery->to_address);

        $refusal = app(EligibilityResolver::class)->reasonNotToContact(
            $recipient,
            $this->delivery->channel,
            (string) $this->delivery->to_address
        );

        if ($refusal !== null) {
            $this->delivery->finishWithoutSending(DeliveryStatusEnum::SKIPPED, $refusal);

            return $this->delivery;
        }

        $content = Content::fromArray($request->content);

        try {
            $result = app(TransportManager::class)
                ->for($connection->provider, $this->delivery->channel)
                ->send($this->delivery, $content);
        } catch (Throwable $exception) {
            report($exception);

            $this->delivery->forceFill([
                'status' => DeliveryStatusEnum::UNKNOWN,
                'error_code' => 'outcome_lost',
                'error' => $exception->getMessage(),
            ])->saveOrFail();

            return $this->delivery;
        }

        $result->status === DeliveryStatusEnum::ACCEPTED
            ? $this->accept($result, $content)
            : $this->fail($result, $recipient);

        return $this->delivery;
    }

    /**
     * Pending → sending as one conditional write, so two workers holding the same job cannot both
     * reach the provider.
     */
    private function claim(): bool
    {
        return Delivery::query()
            ->whereKey($this->delivery->getKey())
            ->where('status', DeliveryStatusEnum::PENDING->value)
            ->update(['status' => DeliveryStatusEnum::SENDING->value, 'attempts' => $this->delivery->attempts + 1]) === 1;
    }

    private function accept(TransportResult $result, Content $content): void
    {
        $this->delivery->forceFill([
            'status' => DeliveryStatusEnum::ACCEPTED,
            'external_id' => $result->externalId,
            'warnings' => $result->warnings ?: null,
            'accepted_at' => now(),
            'last_event_at' => now(),
        ])->saveOrFail();

        // The message is out. Failing to file it is a history problem to report, never a reason to
        // tell the caller a sent message was not sent.
        try {
            new FileDeliveryMessageAction($this->delivery, $content)->execute();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function fail(TransportResult $result, Recipient $recipient): void
    {
        $this->delivery->finishWithoutSending(
            DeliveryStatusEnum::FAILED,
            (string) $result->errorCode,
            $result->error
        );

        if (! $result->recipientOptedOut) {
            return;
        }

        $this->delivery->markRecipientOptedOut();

        if ($this->delivery->channel->isAddressedByPhone()) {
            $recipient->people?->setPhoneOptOut((string) $this->delivery->to_address);
        }
    }
}
