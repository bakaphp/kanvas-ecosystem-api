<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Seats\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Seats\Models\SeatAssignment;

class EndSeatAction
{
    public function __construct(
        protected readonly SeatAssignment $seat,
        protected readonly ?string $effectiveTo = null,
    ) {
    }

    public function execute(): SeatAssignment
    {
        return DB::connection('hr')->transaction(function () {
            $this->seat->effective_to = $this->effectiveTo ?? now()->toDateString();
            $this->seat->saveOrFail();

            // If this was the employee's primary seat, clear the derived department cache.
            if ($this->seat->is_primary) {
                $employee = $this->seat->employee;
                if ($employee !== null && $employee->department_id === $this->seat->department_id) {
                    $employee->department_id = null;
                    $employee->saveOrFail();
                }
            }

            return $this->seat;
        });
    }
}
