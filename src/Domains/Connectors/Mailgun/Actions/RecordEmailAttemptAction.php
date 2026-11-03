<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Kanvas\Connectors\Mailgun\Enums\DeliveryStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Actions\RecordDeliveryOnMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\MessageAttempt;

/**
 * Files an email send as a delivery attempt. An accepted one is keyed by its Message-Id: that id is the
 * only thing Mailgun's later events (delivered, failed, complained...) carry that leads back to it. A
 * rejected one never got an id, so it is filed `failed` under the attempt uuid instead.
 *
 * The Message is often written after the send (a lead send persists its history once the provider
 * answered), so it is linked here only when the caller already has it; otherwise
 * LinkMessageAttemptAction links it from the send result.
 */
class RecordEmailAttemptAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly ?Lead $lead = null,
        private readonly ?Message $message = null,
        private readonly ?string $attemptUuid = null,
    ) {
    }

    public function execute(
        string $mailgunMessageId,
        string $domain,
        string $from,
        string $to,
    ): MessageAttempt {
        $attempt = MessageAttempt::query()->firstOrNew([
            'provider' => DeliveryStatusEnum::ACCEPTED->provider(),
            'provider_message_id' => self::normalizeMessageId($mailgunMessageId),
        ]);
        $attempt->fill([
            'current_status' => $attempt->current_status ?? DeliveryStatusEnum::ACCEPTED->value,
            'sent_at' => $attempt->sent_at ?? now(),
        ]);

        return $this->save($attempt, $domain, $from, $to);
    }

    public function executeRejected(
        string $error,
        string $domain,
        string $from,
        string $to,
    ): MessageAttempt {
        $attempt = new MessageAttempt();
        $attempt->fill([
            'provider' => DeliveryStatusEnum::FAILED->provider(),
            'current_status' => DeliveryStatusEnum::FAILED->value,
            'last_error_message' => $error,
            'classification' => 'rejected_by_provider',
            'terminal_at' => now(),
        ]);

        return $this->save($attempt, $domain, $from, $to);
    }

    private function save(MessageAttempt $attempt, string $domain, string $from, string $to): MessageAttempt
    {
        if (! $attempt->exists && $this->attemptUuid !== null) {
            $attempt->uuid = $this->attemptUuid;
        }

        $attempt->fill([
            'apps_id' => $this->agent->apps_id,
            'companies_id' => $this->agent->companies_id,
            'message_id' => $this->message?->getId() ?? $attempt->message_id,
            'lead_id' => $this->lead?->getId() ?? $attempt->lead_id,
            'provider_account' => $domain,
            'from_address' => $from,
            'to_address' => $to,
        ]);
        $attempt->saveOrFail();

        $status = $attempt->deliveryStatus();
        if ($this->message !== null && $status !== null) {
            new RecordDeliveryOnMessageAction($this->message)->execute(
                $status,
                $attempt->provider_message_id,
                errorMessage: $attempt->last_error_message,
            );
        }

        return $attempt;
    }

    /**
     * The send API answers `<20261103.1@mg.example.com>`; every event spells the same id without the
     * angle brackets.
     */
    public static function normalizeMessageId(string $messageId): string
    {
        return trim(trim($messageId), '<>');
    }
}
