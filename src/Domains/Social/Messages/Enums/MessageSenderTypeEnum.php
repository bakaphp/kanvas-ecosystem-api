<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Enums;

/**
 * Who sent a communication message — the single source of truth behind the
 * `messages.sender_type` column. Classification is derived from the JSON `message`
 * payload keys (`from_me`, `from_ia`, `from_orchestrator`) so the observer, the
 * backfill commands, and any future reader all agree on the same rules.
 *
 * A message with no `from_me` key is not a communication message (social post,
 * comment, system row) and has no sender type — the column stays NULL and it is
 * excluded from the Engage usage breakdown.
 *
 * `messages.people_id` is gated on the same classification: CreateMessageAction only
 * attaches a person when fromPayload() returns non-null, so the two columns can never
 * disagree about whether a row is a real customer message.
 */
enum MessageSenderTypeEnum: string
{
    case USER = 'user';       // human, outbound (from_me = true, not AI)
    case AGENT = 'agent';     // AI agent, outbound (from_ia / from_orchestrator = true)
    case CONTACT = 'contact'; // the customer, inbound (from_me = false)

    public function label(): string
    {
        return match ($this) {
            self::USER => 'Team Member',
            self::AGENT => 'AI Agent',
            self::CONTACT => 'Customer',
        };
    }

    /**
     * Classify a decoded message payload. Returns null for non-communication
     * messages (payloads without a `from_me` key).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        if (! array_key_exists('from_me', $payload)) {
            return null;
        }

        if (! self::isTruthy($payload['from_me'])) {
            return self::CONTACT;
        }

        if (self::isTruthy($payload['from_ia'] ?? null) || self::isTruthy($payload['from_orchestrator'] ?? null)) {
            return self::AGENT;
        }

        return self::USER;
    }

    /**
     * JSON payloads written by different producers store booleans inconsistently
     * (native bool, 1/0, "true"/"false"), so normalize before classifying.
     */
    private static function isTruthy(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value === 1,
            is_string($value) => in_array(strtolower($value), ['1', 'true'], true),
            default => false,
        };
    }
}
