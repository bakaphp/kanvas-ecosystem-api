<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Kanvas\Guild\Leads\Summary\Output\CommitmentOperation;
use Kanvas\Guild\Leads\Summary\Output\LeadSummaryUpdate;
use LogicException;

/**
 * What the model is asked and how its validated answer becomes the next state. No I/O: the update
 * action and the evaluation suite run exactly this.
 *
 * Items are copied forward as they are; only an operation changes one. An item the model leaves out is
 * never deleted, a resolved or replaced one stays in the record, closed.
 */
final class LeadSummaryFold
{
    /**
     * @param list<string> $rejections
     */
    public static function prompt(
        string $title,
        LeadSummaryState $state,
        LeadSummaryBatch $batch,
        array $rejections = []
    ): string {
        $items = array_map(
            static fn (array $item): string => sprintf(
                '- item_id %s [%s] %s%s',
                $item['id'],
                $item['kind'],
                $item['text'],
                isset($item['source_changed_at']) ? ' (source changed)' : '',
            ),
            $state->openItems(),
        );

        $parts = [
            "Lead: {$title}",
            "Current summary:\n" . ($state->sections === [] ? '(none yet)' : (string) json_encode($state->sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            "Open items:\n" . ($items === [] ? '(none)' : implode("\n", $items)),
            "Next messages:\n" . implode("\n", $batch->lines()),
        ];

        if ($rejections !== []) {
            $parts[] = "Your previous answer was rejected and nothing was saved:\n- " . implode("\n- ", $rejections);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Only for an update LeadSummaryUpdateValidator passed.
     */
    public static function apply(LeadSummaryState $state, LeadSummaryUpdate $update, LeadSummaryBatch $batch): LeadSummaryState
    {
        $items = $state->items;
        $now = CarbonImmutable::now()->toDateTimeString();

        foreach ($update->operations as $operation) {
            $citations = array_values(array_unique(array_map(static fn ($citation): int => $citation->message_id, $operation->citations)));
            $citedAt = max(array_map($batch->dateOf(...), $citations));

            if ($operation->op === CommitmentOperation::ADD) {
                $items[] = self::item(
                    (string) $operation->kind,
                    (string) $operation->text,
                    $citations,
                    $citedAt,
                );

                continue;
            }

            $index = self::indexOf($items, (string) $operation->item_id);

            if ($operation->op === CommitmentOperation::KEEP) {
                unset($items[$index]['source_changed_at']);
                $items[$index]['citations'] = array_values(array_unique([...$items[$index]['citations'], ...$citations]));

                continue;
            }

            $items[$index]['status'] = $operation->op === CommitmentOperation::RESOLVE ? LeadSummaryState::RESOLVED : LeadSummaryState::REPLACED;
            $items[$index]['closed_at'] = $now;
            $items[$index]['closed_by'] = $citations;

            if ($operation->op === CommitmentOperation::REPLACE) {
                $successor = self::item(
                    $items[$index]['kind'],
                    (string) $operation->text,
                    $citations,
                    $citedAt,
                );
                $successor['replaces'] = $items[$index]['id'];
                $items[$index]['replaced_by'] = $successor['id'];
                $items[] = $successor;
            }
        }

        return self::covered(
            $state,
            self::sections($update),
            array_values($items),
            $batch->highestId(),
        );
    }

    /**
     * Models write an empty field as the word "null" often enough that it would reach the prompt as a fact.
     *
     * @return array<string, string|null>
     */
    private static function sections(LeadSummaryUpdate $update): array
    {
        return array_map(
            static fn (?string $value): ?string => in_array(Str::lower(trim((string) $value)), ['', 'null', 'none', 'n/a'], true) ? null : trim((string) $value),
            $update->sections->toArray(),
        );
    }

    /**
     * The next state's coverage. A rebuild ends once it has re-read everything the summary covered.
     *
     * @param array<string, string|null> $sections
     * @param list<array<string, mixed>> $items
     */
    public static function covered(
        LeadSummaryState $state,
        array $sections,
        array $items,
        int $highest
    ): LeadSummaryState {
        return new LeadSummaryState(
            sections: $sections,
            items: $items,
            through: max($state->through, $highest),
            rebuildFrom: $state->rebuildFrom !== null && $highest < $state->through ? $highest + 1 : null,
        );
    }

    /**
     * @param list<int> $citations
     * @return array<string, mixed>
     */
    private static function item(
        string $kind,
        string $text,
        array $citations,
        string $createdAt
    ): array {
        return [
            'id' => LeadSummaryState::newItemId(),
            'kind' => $kind,
            'text' => trim($text),
            'status' => LeadSummaryState::OPEN,
            'citations' => $citations,
            'created_at' => $createdAt,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function indexOf(array $items, string $id): int
    {
        foreach ($items as $index => $item) {
            if ($item['id'] === $id) {
                return $index;
            }
        }

        // The validator already refused an unknown id; reaching here is a bug, not a model error.
        throw new LogicException("Lead summary item {$id} vanished between validation and apply");
    }
}
