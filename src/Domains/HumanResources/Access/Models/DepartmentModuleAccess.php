<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Access\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Models\BaseModel;
use Override;

/**
 * @property int    $id
 * @property int    $apps_id
 * @property int    $companies_id
 * @property int    $department_id
 * @property string $module_slug
 * @property string $level
 */
class DepartmentModuleAccess extends BaseModel
{
    protected $table = 'hr_department_module_access';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_department_module_access';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
