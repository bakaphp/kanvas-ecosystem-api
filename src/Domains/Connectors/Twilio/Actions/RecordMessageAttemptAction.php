<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Actions;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Models\MessageAttempt;
use Kanvas\Connectors\Twilio\Models\MessageDeliveryEvent;
use Kanvas\Social\Messages\Models\Message;

final class RecordMessageAttemptAction
{
    public function __construct(
        private readonly ?Message $message = null,
        private readonly ?Apps $app = null,
        private readonly ?Companies $company = null,
        private readonly ?int $leadId = null,
    ) {
    }

    /** @return array<int, MessageAttempt> */
    public function execute(array $providerResponse): array
    {
        $providerMessages = data_get($providerResponse, 'messages', []);
        $providerMessages = is_array($providerMessages) ? $providerMessages : [];
        $attemptUuid = (string) ($providerResponse['attempt_uuid'] ?? Str::uuid());

        if ($providerMessages === []) {
            $providerMessages = [[
                'sid' => null,
                'status' => 'failed',
                'error_code' => $providerResponse['twilio_error_code'] ?? null,
                'error_message' => $providerResponse['error'] ?? null,
                'account_sid' => $providerResponse['account_sid'] ?? null,
                'from' => $providerResponse['from'] ?? null,
                'to' => $providerResponse['to'] ?? null,
            ]];
        }

        $attempts = [];
        foreach ($providerMessages as $index => $providerMessage) {
            if (! is_array($providerMessage)) {
                continue;
            }

            $sid = self::nullableString($providerMessage['sid'] ?? null);
            $uuid = $index === 0 ? $attemptUuid : (string) Str::uuid();
            $status = self::nullableString($providerMessage['status'] ?? null) ?? 'failed';
            $errorCode = self::nullableInteger(
                $providerMessage['error_code'] ?? $providerResponse['twilio_error_code'] ?? null,
            );
            $errorMessage = self::nullableString(
                $providerMessage['error_message'] ?? $providerResponse['error'] ?? null,
            );

            $attempt = MessageAttempt::query()->firstOrNew(
                $sid !== null ? ['message_sid' => $sid] : ['uuid' => $uuid],
            );
            $attempt->fill([
                'uuid' => $attempt->uuid ?: $uuid,
                'apps_id' => $this->message?->apps_id ?? $this->app?->getId(),
                'companies_id' => $this->message?->companies_id ?? $this->company?->getId(),
                'message_id' => $this->message?->getId() ?? $attempt->message_id,
                'lead_id' => $this->leadId ?? $attempt->lead_id,
                'message_sid' => $sid,
                'account_sid' => self::nullableString($providerMessage['account_sid'] ?? null),
                'messaging_service_sid' => self::nullableString($providerMessage['messaging_service_sid'] ?? null),
                'from_number' => self::nullableString($providerMessage['from'] ?? null),
                'to_number' => self::nullableString($providerMessage['to'] ?? null),
                'current_status' => $status,
                'last_error_code' => $errorCode,
                'last_error_message' => $errorMessage,
                'classification' => self::nullableString($providerResponse['classification'] ?? null),
                'remediation_action' => self::remediationAction($providerResponse),
                'retry_number' => (int) ($providerResponse['retry_number'] ?? $attempt->retry_number ?? 0),
                'parent_attempt_id' => $providerResponse['parent_attempt_id'] ?? $attempt->parent_attempt_id,
                'terminal_at' => self::isTerminal($status) ? now() : null,
            ]);
            $attempt->saveOrFail();

            $eventPayload = [
                'provider_message_index' => $index,
                'attempt_uuid' => $attemptUuid,
                'message_sid' => $sid,
                'status' => $status,
                'error_code' => $errorCode,
                'classification' => $attempt->classification,
                'remediation_action' => $attempt->remediation_action,
            ];
            $eventSource = match (true) {
                $sid !== null => 'api_response',
                $errorCode !== null => 'synchronous_api',
                default => 'pre_send',
            };
            $eventKey = self::eventKey($attempt->getId(), $eventSource, $eventPayload);

            MessageDeliveryEvent::query()->firstOrCreate(
                ['event_key' => $eventKey],
                [
                    'uuid' => (string) Str::uuid(),
                    'apps_id' => $attempt->apps_id,
                    'companies_id' => $attempt->companies_id,
                    'attempt_id' => $attempt->getId(),
                    'source' => $eventSource,
                    'status' => $status,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'classification' => $attempt->classification,
                    'remediation_action' => $attempt->remediation_action,
                    'payload' => $eventPayload,
                    'received_at' => now(),
                    'processed_at' => now(),
                    'processing_result' => 'attempt_snapshot_updated',
                ],
            );

            $attempts[] = $attempt;
        }

        return $attempts;
    }

    public static function eventKey(int $attemptId, string $source, array $payload): string
    {
        ksort($payload);

        return hash('sha256', $attemptId . '|' . $source . '|' . json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public static function isTerminal(string $status): bool
    {
        return in_array(strtolower($status), ['delivered', 'undelivered', 'failed', 'canceled'], true);
    }

    private static function remediationAction(array $providerResponse): ?string
    {
        return match ($providerResponse['classification'] ?? null) {
            'opted_out' => 'suppress_sms',
            'registration_hold' => 'hold_sender',
            'configuration_error', 'validation_failed' => 'no_retry',
            default => ($providerResponse['retryable'] ?? false) ? 'retry' : null,
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
