<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Models\BaseModel;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Override;

/**
 * @property int   $id
 * @property int   $apps_id
 * @property int   $companies_id
 * @property int   $employee_id
 * @property int   $leave_type_id
 * @property int   $period_year
 * @property float $entitled_days
 * @property float $accrued_days
 * @property float $carried_over_days
 * @property float $used_days
 * @property float $pending_days
 */
class LeaveBalance extends BaseModel
{
    use EmitsLedgerEventsForEntity;

    protected $table = 'hr_leave_balances';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'entitled_days' => 'float',
        'accrued_days' => 'float',
        'carried_over_days' => 'float',
        'used_days' => 'float',
        'pending_days' => 'float',
    ];

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_leave_balances';
    }

    protected function sourceDomainForLedger(): string
    {
        return 'HumanResources';
    }

    public function getAvailableDaysAttribute(): float
    {
        return $this->entitled_days
            + $this->accrued_days
            + $this->carried_over_days
            - $this->used_days
            - $this->pending_days;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
