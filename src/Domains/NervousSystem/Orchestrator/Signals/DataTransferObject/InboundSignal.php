<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
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
    private const int EXCERPT_CHARS = 1500;

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

    public function isSegment(): bool
    {
        return isset($this->metadata['segment_of']);
    }

    /**
     * The whole signal as one text: what its file holds, and the message body when no file was stored.
     */
    public function document(): string
    {
        return implode("\n\n", array_filter([
            $this->headline(),
            $this->participantsLine(),
            $this->reportLine(),
            ...$this->summarySections(),
            ucfirst($this->kind->bodyLabel()) . ":\n" . $this->content,
        ]));
    }

    /**
     * The message body once the full text lives in $file. The file is named on the second line because
     * the PM's wake only quotes the opening of an ingest. A segment shows its own slice: the source's
     * summary covers the whole signal, not the part routed here.
     */
    public function digest(Filesystem $file): string
    {
        $summary = $this->summarySections();

        $body = match (true) {
            $this->isSegment() => ["Relevant part:\n" . $this->content],
            $summary !== [] => $summary,
            default => ["Opening:\n" . Str::limit($this->content, self::EXCERPT_CHARS)],
        };

        return implode("\n\n", array_filter([
            $this->headline(),
            sprintf(
                'Full %s: attached file "%s" (filesystem_id %d)',
                $this->kind->bodyLabel(),
                $file->name,
                $file->getId()
            ),
            $this->participantsLine(),
            $this->reportLine(),
            ...$body,
        ]));
    }

    public function fileName(): string
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $this->occurredAt, $match) === 1 ? $match[0] : '';

        $parts = [$this->kind->noun(), Str::slug(Str::limit($this->title, 60, '')), $date];

        return implode('-', array_filter($parts)) . '.md';
    }

    private function headline(): string
    {
        return sprintf(
            '%s: "%s"%s',
            ucfirst($this->kind->noun()),
            $this->title,
            $this->occurredAt !== null ? " ({$this->occurredAt})" : ''
        );
    }

    private function participantsLine(): ?string
    {
        $people = array_map(
            static fn (array $actor): string => trim($actor['name'] . ($actor['email'] !== null ? " <{$actor['email']}>" : '')),
            $this->actors
        );

        return $people !== [] ? 'Participants: ' . implode(', ', $people) : null;
    }

    private function reportLine(): ?string
    {
        return ! empty($this->metadata['report_url']) ? 'Report: ' . $this->metadata['report_url'] : null;
    }

    /**
     * @return list<string>
     */
    private function summarySections(): array
    {
        return array_values(array_filter([
            ! empty($this->metadata['summary']) ? "Summary:\n" . $this->metadata['summary'] : null,
            $this->listSection('Chapters', 'chapters'),
            $this->listSection('Action items', 'action_items'),
            $this->listSection('Key questions', 'key_questions'),
            $this->listSection('Topics', 'topics'),
        ]));
    }

    private function listSection(string $label, string $key): ?string
    {
        $items = (array) ($this->metadata[$key] ?? []);

        return $items !== [] ? "{$label}:\n- " . implode("\n- ", $items) : null;
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
