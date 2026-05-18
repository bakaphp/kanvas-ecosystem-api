<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\DataTransferObject;

/**
 * Internal aggregate row for one date window. Sum-friendly across days
 * (e.g. THIS_WEEK = sum of seven daily snapshots).
 *
 * `system_confidence_pct` is the **weighted average** across plans, not
 * a simple sum — combine() handles that correctly using each day's
 * plan count as the weight (held in `prevented_issues_basis_plans`).
 *
 * Internal use only — never exposed to GraphQL.
 */
class PulseAggregateRow
{
    public function __construct(
        public int $signals_count = 0,
        public int $understand_count = 0,
        public int $decide_count = 0,
        public int $actions_executed = 0,
        public int $warnings_count = 0,
        public int $prevented_issues = 0,
        public ?float $system_confidence_pct = null,
        public int $confidence_basis_count = 0, // # plans the confidence average is based on
    ) {
    }

    public function addRow(self $other): void
    {
        $this->signals_count += $other->signals_count;
        $this->understand_count += $other->understand_count;
        $this->decide_count += $other->decide_count;
        $this->actions_executed += $other->actions_executed;
        $this->warnings_count += $other->warnings_count;
        $this->prevented_issues += $other->prevented_issues;

        // Weighted average for confidence: weight by number of plans
        // that contributed to each day's average.
        $selfTotal = ($this->system_confidence_pct ?? 0.0) * $this->confidence_basis_count;
        $otherTotal = ($other->system_confidence_pct ?? 0.0) * $other->confidence_basis_count;
        $combinedBasis = $this->confidence_basis_count + $other->confidence_basis_count;

        if ($combinedBasis > 0) {
            $this->system_confidence_pct = ($selfTotal + $otherTotal) / $combinedBasis;
            $this->confidence_basis_count = $combinedBasis;
        }
        // else both windows had no plans → keep current state (null/0)
    }
}
