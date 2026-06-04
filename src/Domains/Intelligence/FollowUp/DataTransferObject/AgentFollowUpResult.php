<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * Parsed JSON response from FollowUpAgent. Shape contract enforced via the
 * agent's instructions(): `{ should_respond, advance_stage, message?, reason? }`.
 * Booleans combine — neither true = exhaust with `agent: <reason>`.
 */
class AgentFollowUpResult extends Data
{
    public function __construct(
        public readonly bool $shouldRespond,
        public readonly bool $advanceStage,
        public readonly ?string $message = null,
        public readonly ?string $reason = null,
    ) {
    }

    // Defensive — tolerates ```json fences some models emit despite instructions.
    public static function fromKernelResponse(string $raw): self
    {
        $json = self::stripJsonFences(trim($raw));
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return new self(
                shouldRespond: false,
                advanceStage: false,
                reason: 'invalid_json_response'
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
            $raw = preg_replace('/^```(?:json)?\s*/', '', $raw) ?? $raw;
            $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        }

        return trim($raw);
    }
}
