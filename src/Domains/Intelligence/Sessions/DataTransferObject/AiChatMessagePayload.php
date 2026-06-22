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
     * @param list<string> $image_descriptions Vision-generated captions, parallel to $images.
     *                                          Backfilled async by CaptionMessageImagesJob so the
     *                                          agent's text-only history "remembers" what each image
     *                                          was (the live turn sees the real bytes; later turns
     *                                          only have this text).
     */
    public function __construct(
        public readonly ?string $content,
        public readonly bool $from_me,
        public readonly bool $from_ia,
        public readonly ?string $session_id = null,
        public readonly ?int $agent_id = null,
        public readonly mixed $raw_data = null,
        public readonly ?string $message_id = null,
        public readonly ?string $chat_jid = null,
        public readonly array $images = [],
        public readonly array $image_descriptions = [],
    ) {
    }

    /**
     * Drop null fields so stored JSON only carries keys the writer actually populated.
     * `content` is exempt — it's a hard contract for downstream readers, so a null content
     * (image-only MMS, sticker, reaction) is coerced to an empty string rather than stripped.
     * `image_descriptions` is also dropped when empty: it's never set at write time (the caption
     * job backfills it later via Message::addMessage), so keeping it would add a dead `[]` to
     * every message.
     */
    #[Override]
    public function toArray(): array
    {
        $array = array_filter(
            parent::toArray(),
            static fn (mixed $value): bool => $value !== null,
        );

        $array['content'] ??= '';

        if (($array['image_descriptions'] ?? null) === []) {
            unset($array['image_descriptions']);
        }

        return $array;
    }
}
