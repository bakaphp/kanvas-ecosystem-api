<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Departments\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Access\Models\DepartmentModuleAccess;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Models\BaseModel;
use Kanvas\HumanResources\Seats\Models\SeatAssignment;
use Nevadskiy\Tree\AsTree;
use Override;

/**
 * @property int         $id
 * @property string      $uuid
 * @property int         $apps_id
 * @property int         $companies_id
 * @property int|null    $companies_branches_id
 * @property int|null    $users_id
 * @property int|null    $parent_id
 * @property string|null $path
 * @property int|null    $manager_employee_id
 * @property string      $name
 * @property string      $slug
 * @property string|null $code
 * @property string|null $outcome_line
 * @property string|null $description
 */
class Department extends BaseModel
{
    use AsTree;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use SlugTrait;
    use UuidTrait;

    protected $table = 'hr_departments';
    protected $guarded = [];

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_departments';
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(SeatAssignment::class, 'department_id');
    }

    public function moduleAccess(): HasMany
    {
        return $this->hasMany(DepartmentModuleAccess::class, 'department_id');
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_hr_department_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'hr_department_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'code' => $this->code,
            'outcome_line' => $this->outcome_line,
            'description' => $this->description,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->is_deleted;
    }

    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string', 'optional' => true],
                ['name' => 'slug', 'type' => 'string', 'optional' => true],
                ['name' => 'code', 'type' => 'string', 'optional' => true],
                ['name' => 'outcome_line', 'type' => 'string', 'optional' => true],
                ['name' => 'description', 'type' => 'string', 'optional' => true],
                ['name' => 'apps_id', 'type' => 'int64'],
                ['name' => 'companies_id', 'type' => 'int64'],
            ],
        ];
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();
        if ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }
        if ($query->model->isTypesense()) {
            $query->options(['query_by' => 'name,slug,code,outcome_line,description']);
        }

        return $query;
    }
}
