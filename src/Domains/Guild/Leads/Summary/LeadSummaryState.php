<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

use Illuminate\Support\Str;
use Kanvas\Guild\Leads\Summary\Output\LeadSummarySections;

/**
 * The rolling summary of one lead, as stored on its summary note under `lead_summary`: the sections the
 * model rewrites on every update, the items (commitments and staff instructions) only validated
 * operations may change, and the coverage marker. Saved whole, in one write, so a reader never sees new
 * items with an old marker.
 *
 * `through` is the highest counted message id the summary was built from; a counted message above it is
 * not covered. `rebuildFrom` is set when a message at or below `through` changed (edited, deleted, or
 * attached late): the summary is not current from that id on until a rebuild reaches `through` again.
 */
final class LeadSummaryState
{
    public const string PAYLOAD_KEY = 'lead_summary';

    public const string OPEN = 'open';
    public const string RESOLVED = 'resolved';
    public const string REPLACED = 'replaced';

    private const array SECTION_LABELS = [
        'interest' => 'Looking for',
        'budget_payment' => 'Budget / payment',
        'location' => 'Location',
        'appointments' => 'Appointments',
        'objections' => 'Objections',
        'current_state' => 'Where it stands',
    ];

    /**
     * @param array<string, mixed> $sections the six text sections plus `key_facts`, a list of {key, value}
     * @param list<array{id: string, kind: string, text: string, status: string, citations: list<int>, created_at: string, closed_at?: string|null, closed_by?: list<int>, replaces?: string|null, replaced_by?: string|null, source_changed_at?: string|null}> $items
     */
    public function __construct(
        public readonly array $sections = [],
        public readonly array $items = [],
        public readonly int $through = 0,
        public readonly ?int $rebuildFrom = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload the note's whole message payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $state = $payload[self::PAYLOAD_KEY] ?? null;

        if (! is_array($state)) {
            return null;
        }

        return new self(
            sections: (array) ($state['sections'] ?? []),
            items: array_values((array) ($state['items'] ?? [])),
            through: (int) ($state['through'] ?? 0),
            rebuildFrom: isset($state['rebuild_from']) ? (int) $state['rebuild_from'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'sections' => $this->sections,
            'items' => $this->items,
            'through' => $this->through,
            'rebuild_from' => $this->rebuildFrom,
        ];
    }

    /**
     * The highest id the summary can vouch for: below a pending rebuild only.
     */
    public function coveredThrough(): int
    {
        return $this->rebuildFrom === null ? $this->through : min($this->through, $this->rebuildFrom - 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function openItems(): array
    {
        return array_values(array_filter($this->items, static fn (array $item): bool => $item['status'] === self::OPEN));
    }

    public function findItem(string $id): ?array
    {
        foreach ($this->items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * A message at or below the marker changed: coverage restarts from it, and every open item that
     * cites it is flagged until a rebuild keeps, replaces or resolves it.
     */
    public function withChangedMessage(int $messageId, string $changedAt): self
    {
        if ($messageId > $this->through) {
            return $this;
        }

        return new self(
            sections: $this->sections,
            items: array_map(
                static fn (array $item): array => $item['status'] === self::OPEN && in_array($messageId, $item['citations'], true)
                    ? [...$item, 'source_changed_at' => $changedAt]
                    : $item,
                $this->items,
            ),
            through: $this->through,
            rebuildFrom: $this->rebuildFrom === null ? $messageId : min($this->rebuildFrom, $messageId),
        );
    }

    public static function newItemId(): string
    {
        return 'item_' . Str::lower(Str::random(10));
    }

    /**
     * What the agent reads: the sections, then every open item with its date. Resolved and replaced items
     * stay in the payload for the record and the evaluation, not in the prompt.
     */
    public function render(): string
    {
        $lines = [];

        foreach (self::SECTION_LABELS as $key => $label) {
            $value = trim((string) ($this->sections[$key] ?? ''));

            if ($value !== '') {
                $lines[] = "- {$label}: {$value}";
            }
        }

        foreach ((array) ($this->sections[LeadSummarySections::KEY_FACTS] ?? []) as $fact) {
            if (is_array($fact) && trim((string) ($fact['value'] ?? '')) !== '') {
                $lines[] = '- ' . Str::headline((string) $fact['key']) . ': ' . trim((string) $fact['value']);
            }
        }

        $open = $this->openItems();

        if ($open !== []) {
            $lines[] = '';
            $lines[] = 'Open commitments and staff instructions (still in force until resolved):';

            foreach ($open as $item) {
                $changed = isset($item['source_changed_at'])
                    ? " (source changed on {$item['source_changed_at']}, verify before relying on it)"
                    : '';
                $lines[] = sprintf(
                    '- [%s, %s] %s%s',
                    $item['kind'],
                    substr((string) $item['created_at'], 0, 10),
                    $item['text'],
                    $changed,
                );
            }
        }

        return implode("\n", $lines);
    }
}
