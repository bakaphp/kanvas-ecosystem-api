<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Models\BaseModel;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Override;

/**
 * @property int         $id
 * @property string      $uuid
 * @property int         $apps_id
 * @property int         $companies_id
 * @property int         $employee_id
 * @property int         $leave_type_id
 * @property float       $days
 * @property string      $status
 * @property int|null    $approver_employee_id
 * @property string|null $reason
 */
class LeaveRequest extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'hr_leave_requests';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'days' => 'float',
        'start_date' => 'date',
        'end_date' => 'date',
        'decided_at' => 'datetime',
    ];

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_leave_requests';
    }

    protected function sourceDomainForLedger(): string
    {
        return 'HumanResources';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }
}
