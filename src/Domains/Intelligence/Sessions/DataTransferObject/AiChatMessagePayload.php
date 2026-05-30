<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\DataTransferObject;

use Override;
use Spatie\LaravelData\Data;

/**
 * Canonical baseline of `messages.message` for AI chat writes. `from_me` mirrors the connector
 * convention (true = our/agent side); `from_ia` flags AI-authored vs human-authored even when
 * both sit on the from_me side. `raw_data` carries the source service's original payload —
 * string for outbound replies, the inbound request array for webhook writes. Connectors with
 * extra per-service keys (Mailgun's `from_email`/`subject`, etc.) spread the DTO's output and
 * append their own keys on top — keeps this DTO the shared baseline rather than a union of
 * every service's fields.
 */
class AiChatMessagePayload extends Data
{
    /**
     * @param list<string> $images
     */
    public function __construct(
        public readonly string $content,
        public readonly bool $from_me,
        public readonly bool $from_ia,
        public readonly ?string $session_id = null,
        public readonly ?int $agent_id = null,
        public readonly mixed $raw_data = null,
        public readonly ?string $message_id = null,
        public readonly ?string $chat_jid = null,
        public readonly array $images = [],
    ) {
    }

    /**
     * Drop null fields so stored JSON only carries keys the writer actually populated.
     */
    #[Override]
    public function toArray(): array
    {
        return array_filter(
            parent::toArray(),
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
