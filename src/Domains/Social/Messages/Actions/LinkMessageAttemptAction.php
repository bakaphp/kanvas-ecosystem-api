<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Baka\Support\Str;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\MessageAttempt;

/**
 * Points an attempt filed at send time at the Message written afterwards, and copies the state the
 * attempt already reached onto it — `accepted`, or `failed` when the provider rejected the send — so
 * the message shows it before any webhook arrives. Leaves an attempt already linked to a message alone.
 *
 * The send result finds the attempt by the provider's message id, or by the attempt uuid when the
 * provider refused the send and never issued one.
 */
class LinkMessageAttemptAction
{
    public function __construct(
        private readonly Message $message,
    ) {
    }

    public function execute(array $sendResult): ?MessageAttempt
    {
        $attempt = $this->findAttempt($sendResult);

        if (! $attempt instanceof MessageAttempt || $attempt->message_id !== null) {
            return $attempt;
        }

        $attempt->message_id = $this->message->getId();
        $attempt->saveOrFail();

        $status = $attempt->deliveryStatus();
        if ($status !== null) {
            new RecordDeliveryOnMessageAction($this->message)->execute(
                $status,
                $attempt->provider_message_id,
                $attempt->last_error_code !== null ? (string) $attempt->last_error_code : null,
                $attempt->last_error_message,
            );
        }

        return $attempt;
    }

    private function findAttempt(array $sendResult): ?MessageAttempt
    {
        $provider = Str::trimToNull((string) ($sendResult['provider'] ?? ''));
        $providerMessageId = Str::trimToNull((string) ($sendResult['provider_message_id'] ?? ''));
        $attemptUuid = Str::trimToNull((string) ($sendResult['attempt_uuid'] ?? ''));

        if ($provider === null || ($providerMessageId === null && $attemptUuid === null)) {
            return null;
        }

        return MessageAttempt::query()
            ->fromApp($this->message->app)
            ->fromCompany($this->message->company)
            ->where('provider', $provider)
            ->when(
                $providerMessageId !== null,
                fn ($query) => $query->where('provider_message_id', $providerMessageId),
                fn ($query) => $query->where('uuid', $attemptUuid),
            )
            ->first();
    }
}
