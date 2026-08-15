<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Models\MessageAttempt;
use Kanvas\Connectors\Twilio\Models\MessageDeliveryEvent;
use Kanvas\Social\Messages\Models\Message;

final class RecordDeliveryStatusEventAction
{
    public function __construct(
        private readonly ?Message $message,
        private readonly array $payload,
        private readonly ?Apps $app = null,
        private readonly ?Companies $company = null,
    ) {
    }

    /** @return array{attempt: MessageAttempt, event: MessageDeliveryEvent, created: bool} */
    public function execute(): array
    {
        $sid = trim((string) ($this->payload['MessageSid'] ?? $this->payload['SmsSid'] ?? ''));
        $status = trim((string) ($this->payload['MessageStatus'] ?? $this->payload['SmsStatus'] ?? ''));
        $errorCode = is_numeric($this->payload['ErrorCode'] ?? null)
            ? (int) $this->payload['ErrorCode']
            : null;
        $errorMessage = self::nullableString($this->payload['ErrorMessage'] ?? null);

        $attempt = MessageAttempt::query()->firstOrNew(['message_sid' => $sid]);
        if (! $attempt->exists) {
            $attempt->fill([
                'uuid' => (string) Str::uuid(),
                'apps_id' => $this->message?->apps_id ?? $this->app?->getId(),
                'companies_id' => $this->message?->companies_id ?? $this->company?->getId(),
                'message_id' => $this->message?->getId(),
                'message_sid' => $sid,
                'current_status' => $status,
            ]);
            $attempt->saveOrFail();
        }

        $eventKey = RecordMessageAttemptAction::eventKey(
            $attempt->getId(),
            'status_callback',
            $this->payload,
        );
        $existingEvent = MessageDeliveryEvent::query()->where('event_key', $eventKey)->first();
        if ($existingEvent instanceof MessageDeliveryEvent) {
            return [
                'attempt' => $attempt,
                'event' => $existingEvent,
                'created' => false,
            ];
        }

        if (self::canAdvanceStatus((string) $attempt->current_status, $status)) {
            $attempt->fill([
                'current_status' => $status,
                'account_sid' => self::nullableString($this->payload['AccountSid'] ?? null) ?? $attempt->account_sid,
                'messaging_service_sid' => self::nullableString($this->payload['MessagingServiceSid'] ?? null) ?? $attempt->messaging_service_sid,
                'from_number' => self::nullableString($this->payload['From'] ?? null) ?? $attempt->from_number,
                'to_number' => self::nullableString($this->payload['To'] ?? null) ?? $attempt->to_number,
                'last_error_code' => $errorCode,
                'last_error_message' => $errorMessage,
                'classification' => self::classification($errorCode),
                'remediation_action' => self::remediationAction($errorCode),
                'terminal_at' => RecordMessageAttemptAction::isTerminal($status) ? now() : null,
            ]);
        }
        $attempt->saveOrFail();

        $event = MessageDeliveryEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $attempt->apps_id,
            'companies_id' => $attempt->companies_id,
            'attempt_id' => $attempt->getId(),
            'event_key' => $eventKey,
            'source' => 'status_callback',
            'status' => $status,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'classification' => self::classification($errorCode),
            'remediation_action' => self::remediationAction($errorCode),
            'payload' => $this->payload,
            'received_at' => now(),
            'processed_at' => now(),
            'processing_result' => 'attempt_snapshot_updated',
        ]);

        return [
            'attempt' => $attempt,
            'event' => $event,
            'created' => true,
        ];
    }

    public static function canAdvanceStatus(string $currentStatus, string $incomingStatus): bool
    {
        if ($currentStatus === '') {
            return true;
        }

        $terminalStatuses = ['delivered', 'undelivered', 'failed', 'canceled'];
        if (in_array(strtolower($currentStatus), $terminalStatuses, true)) {
            return strtolower($currentStatus) === strtolower($incomingStatus);
        }

        $rank = [
            'accepted' => 10,
            'scheduled' => 10,
            'queued' => 20,
            'sending' => 30,
            'sent' => 40,
            'delivered' => 100,
            'undelivered' => 100,
            'failed' => 100,
            'canceled' => 100,
        ];

        return ($rank[strtolower($incomingStatus)] ?? 0)
            >= ($rank[strtolower($currentStatus)] ?? 0);
    }

    private static function classification(?int $errorCode): ?string
    {
        return match ($errorCode) {
            21610 => 'opted_out',
            21603, 21660 => 'configuration_error',
            30034 => 'registration_hold',
            21211, 30019 => 'validation_failed',
            30003, 30005 => 'temporary_failure',
            30006 => 'sms_unavailable',
            default => null,
        };
    }

    private static function remediationAction(?int $errorCode): ?string
    {
        return match ($errorCode) {
            21610, 30006 => 'suppress_sms',
            30034 => 'hold_sender',
            21211, 21603, 21660, 30019 => 'no_retry',
            30003, 30005 => 'delayed_retry',
            default => null,
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
