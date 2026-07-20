<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Compensation\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Compensation\DataTransferObject\EmployeeCompensation as EmployeeCompensationData;
use Kanvas\HumanResources\Compensation\Models\EmployeeCompensation;

class RecordCompensationAction
{
    public function __construct(
        protected readonly EmployeeCompensationData $data,
    ) {
    }

    public function execute(): EmployeeCompensation
    {
        return DB::connection('hr')->transaction(function () {
            $effectiveFrom = Carbon::parse($this->data->effectiveFrom);

            // Append-only history: close the current comp the day before the new one starts.
            EmployeeCompensation::query()
                ->where('employee_id', $this->data->employee->getId())
                ->whereNull('effective_to')
                ->where('is_deleted', 0)
                ->update(['effective_to' => $effectiveFrom->copy()->subDay()->toDateString()]);

            $comp = new EmployeeCompensation();
            $comp->apps_id = $this->data->app->getId();
            $comp->companies_id = $this->data->company->getId();
            $comp->employee_id = $this->data->employee->getId();
            $comp->pay_band_id = $this->data->payBand?->getId();
            $comp->amount = $this->data->amount;
            $comp->currency = $this->data->currency;
            $comp->pay_frequency = $this->data->payFrequency;
            $comp->effective_from = $effectiveFrom->toDateString();
            $comp->change_reason = $this->data->changeReason;
            $comp->recorded_by_users_id = $this->data->user->getId();
            $comp->saveOrFail();

            // Audit the change WITHOUT the amount — the ledger must not leak the salary number.
            $comp->emitLedgerEvent('compensation.changed', payload: [
                'currency' => $comp->currency,
                'change_reason' => $comp->change_reason,
            ], actorType: 'User', actorId: $this->data->user->getId());

            return $comp;
        });
    }
}
