<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary;

use Kanvas\Guild\Leads\Models\Lead;

/**
 * One turn's view of a lead's history, computed once so the history, the instructions and the memory
 * recall cannot disagree. Ids are Social message ids of the lead's counted messages:
 *
 * - `loaded`: what the rollup returned;
 * - `retained`: what is still in the window after fold and cut, folded-in rows included;
 * - `dropped`: loaded minus retained, holes in the middle included;
 * - `uncovered`: every counted message above what the summary covers that is not retained, so known to
 *   nobody this turn. Not only `dropped`: a customer-facing rollup never loads staff notes, and a note
 *   the summary has not read yet is exactly the instruction that must reach it.
 */
final readonly class LeadHistoryWindow
{
    /**
     * @param list<int> $loaded
     * @param list<int> $retained
     * @param list<int> $dropped
     * @param list<int> $uncovered
     */
    public function __construct(
        public array $loaded,
        public array $retained,
        public array $dropped,
        public array $uncovered,
    ) {
    }

    /**
     * @param array{loaded: list<string>, retained: list<string>} $window row ids from KanvasChatHistory::window()
     */
    public static function of(Lead $lead, array $window, LeadSummaryState $state): self
    {
        $loaded = LeadConversationMessages::countedAmong($lead, self::socialIds($window['loaded']));
        $retained = array_values(array_intersect($loaded, self::socialIds($window['retained'])));

        return new self(
            loaded: $loaded,
            retained: $retained,
            dropped: array_values(array_diff($loaded, $retained)),
            uncovered: array_values(array_diff(
                LeadConversationMessages::idsAfter($lead, $state->coveredThrough()),
                $retained,
            )),
        );
    }

    public function withCoverage(LeadSummaryState $state): self
    {
        return new self(
            loaded: $this->loaded,
            retained: $this->retained,
            dropped: $this->dropped,
            uncovered: array_values(array_filter(
                $this->uncovered,
                static fn (int $id): bool => $id > $state->coveredThrough(),
            )),
        );
    }

    /**
     * The rollup ids every row `social:<id>`; anything else (a live turn) is not a lead message yet.
     *
     * @param list<string> $rowIds
     * @return list<int>
     */
    private static function socialIds(array $rowIds): array
    {
        $ids = [];

        foreach ($rowIds as $rowId) {
            if (str_starts_with($rowId, 'social:')) {
                $ids[] = (int) substr($rowId, 7);
            }
        }

        return $ids;
    }
}
