<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject;

use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;

/**
 * Any inbound signal the orchestrator routes — a meeting transcript, an email, a CRM event, … —
 * normalized to one shape so the routing cascade never sees a source-specific payload. `content` is
 * the body the target project ingests; `kind` (ProjectIngestTypeEnum) is how it's ingested; `actors`
 * drive the deterministic attendee↔member match; `title`+`content` drive the LLM classifier. The
 * routing decision may also conclude NO project is the target (drop) — that's a routing outcome, not a
 * property of the signal.
 */
final readonly class InboundSignal
{
    /**
     * @param list<array{name: string, email: ?string}> $actors
     * @param array<string, mixed> $metadata source-specific extras (topics, action items, raw refs)
     */
    public function __construct(
        public SignalSourceEnum $source,
        public ProjectIngestTypeEnum $kind,
        public string $externalId,
        public string $title,
        public string $content,
        public ?string $occurredAt,
        public array $actors,
        public array $metadata = [],
    ) {
    }

    /**
     * Lower-cased, de-duplicated actor email addresses — the deterministic routing signal.
     *
     * @return list<string>
     */
    public function actorEmails(): array
    {
        $emails = [];
        foreach ($this->actors as $actor) {
            $email = strtolower(trim($actor['email'] ?? ''));
            if ($email !== '') {
                $emails[$email] = true;
            }
        }

        return array_keys($emails);
    }

    /**
     * The domain of each actor email (for matching an external customer/company).
     *
     * @return list<string>
     */
    public function actorDomains(): array
    {
        $domains = [];
        foreach ($this->actorEmails() as $email) {
            $at = strrpos($email, '@');
            if ($at !== false) {
                $domains[substr($email, $at + 1)] = true;
            }
        }

        return array_keys($domains);
    }

    /**
     * One topical slice of this signal for per-segment fan-out — same source/kind/actors, its own
     * title + content. Actors stay parent-wide (segmentation splits by topic, not by who attended).
     */
    public function segment(string $title, string $content, int $index): self
    {
        return new self(
            source: $this->source,
            kind: $this->kind,
            externalId: $this->externalId . '#seg-' . $index,
            title: $title !== '' ? $title : $this->title,
            content: $content,
            occurredAt: $this->occurredAt,
            actors: $this->actors,
            metadata: $this->metadata + ['segment_of' => $this->externalId, 'segment_index' => $index],
        );
    }

    /**
     * A copy carrying different content — used to merge several same-project segments into one ingest.
     */
    public function withContent(string $content): self
    {
        return new self(
            source: $this->source,
            kind: $this->kind,
            externalId: $this->externalId,
            title: $this->title,
            content: $content,
            occurredAt: $this->occurredAt,
            actors: $this->actors,
            metadata: $this->metadata,
        );
    }
}
