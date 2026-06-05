<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Parsed JSON response from FollowUpAgent. Shape contract enforced via the
 * agent's instructions(): `{ should_respond, advance_stage, message?, reason? }`.
 * Booleans combine — neither true = exhaust with `agent: <reason>`.
 */
class AgentFollowUpResult extends Data
{
    private const int RAW_TRUNCATION_LENGTH = 2000;

    public function __construct(
        public readonly bool $shouldRespond,
        public readonly bool $advanceStage,
        public readonly ?string $message = null,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Throws RuntimeException with the raw LLM output in the message when the
     * response can't be parsed as JSON. Caller catches → reports to Sentry →
     * lead skips (not exhausts) so the next cron tick retries.
     */
    public static function fromKernelResponse(string $raw): self
    {
        $json = self::stripJsonFences(trim($raw));
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'Follow-up agent returned non-JSON. Raw (first '
                . self::RAW_TRUNCATION_LENGTH . ' chars): '
                . substr($raw, 0, self::RAW_TRUNCATION_LENGTH)
            );
        }

        return new self(
            shouldRespond: (bool) ($decoded['should_respond'] ?? false),
            advanceStage: (bool) ($decoded['advance_stage'] ?? false),
            message: isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== ''
                ? $decoded['message']
                : null,
            reason: isset($decoded['reason']) && is_string($decoded['reason']) && $decoded['reason'] !== ''
                ? $decoded['reason']
                : null,
        );
    }

    private static function stripJsonFences(string $raw): string
    {
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
            $raw = preg_replace('/\s*```\s*$/', '', $raw) ?? $raw;
        }

        return trim($raw);
    }
}
