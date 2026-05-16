<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\DataTransferObject;

/**
 * Internal aggregate row for one date window. Sum-friendly across days
 * (e.g. THIS_WEEK = sum of seven days). Output-derived metrics use null
 * to mean "not populated by any plan in the window" — addRow() preserves
 * that distinction (null + null = null; null + 5 = 5).
 */
class DashboardAggregateRow
{
    public function __construct(
        public int $plans_completed = 0,
        public int $mistakes_auto_corrected = 0,
        public int $mistakes_escalated = 0,
        public int $agents_active = 0,
        public ?float $time_recovered_hours = null,
        public ?int $value_delivered_cents = null,
        public ?float $estimated_human_hours = null,
    ) {
    }

    public function addRow(self $other): void
    {
        $this->plans_completed += $other->plans_completed;
        $this->mistakes_auto_corrected += $other->mistakes_auto_corrected;
        $this->mistakes_escalated += $other->mistakes_escalated;
        $this->agents_active += $other->agents_active;
        $this->time_recovered_hours = self::addNullable($this->time_recovered_hours, $other->time_recovered_hours);
        $this->value_delivered_cents = self::addNullable($this->value_delivered_cents, $other->value_delivered_cents);
        $this->estimated_human_hours = self::addNullable($this->estimated_human_hours, $other->estimated_human_hours);
    }

    /**
     * @template T of int|float
     * @param T|null $a
     * @param T|null $b
     * @return T|null
     */
    private static function addNullable(int|float|null $a, int|float|null $b): int|float|null
    {
        if ($a === null && $b === null) {
            return null;
        }

        return ($a ?? 0) + ($b ?? 0);
    }
}
