<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Seats\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Models\BaseModel;
use Override;

/**
 * @property int      $id
 * @property int      $apps_id
 * @property int      $companies_id
 * @property int      $employee_id
 * @property int      $department_id
 * @property int      $allocation_pct
 * @property bool     $is_primary
 */
class SeatAssignment extends BaseModel
{
    protected $table = 'hr_seat_assignments';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'is_primary' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_seat_assignments';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
