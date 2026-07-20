<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Models\BaseModel;
use Override;

/**
 * @property int         $id
 * @property string      $uuid
 * @property int         $apps_id
 * @property int         $companies_id
 * @property string      $name
 * @property string      $slug
 * @property bool        $is_paid
 * @property string      $accrual_method
 * @property float|null  $default_annual_days
 * @property float|null  $carryover_max_days
 * @property bool        $requires_approval
 * @property bool        $is_active
 */
class LeaveType extends BaseModel
{
    use SlugTrait;
    use UuidTrait;

    protected $table = 'hr_leave_types';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'is_paid' => 'boolean',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
        'default_annual_days' => 'float',
        'carryover_max_days' => 'float',
    ];

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_leave_types';
    }
}
