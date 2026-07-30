<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Compensation\Models;

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
 * @property int|null    $pay_band_id
 * @property float       $amount
 * @property string      $currency
 * @property string      $pay_frequency
 * @property string|null $change_reason
 */
class EmployeeCompensation extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'hr_employee_compensations';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'amount' => 'float',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_employee_compensations';
    }

    protected function sourceDomainForLedger(): string
    {
        return 'HumanResources';
    }

    public function getCompaRatioAttribute(): ?float
    {
        $mid = $this->payBand?->mid_amount;

        if ($mid === null || $mid == 0.0) {
            return null;
        }

        return round($this->amount / $mid, 3);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function payBand(): BelongsTo
    {
        return $this->belongsTo(PayBand::class, 'pay_band_id');
    }
}
