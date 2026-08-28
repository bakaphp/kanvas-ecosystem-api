<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\HumanResources\Compensation\Models\EmployeeCompensation;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Employees\Observers\EmployeeObserver;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Kanvas\HumanResources\Leave\Models\LeaveBalance;
use Kanvas\HumanResources\Leave\Models\LeaveRequest;
use Kanvas\HumanResources\Models\BaseModel;
use Kanvas\HumanResources\Positions\Models\Position;
use Kanvas\HumanResources\Seats\Models\SeatAssignment;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Social\Messages\Traits\HasMessagesTrait;
use Nevadskiy\Tree\AsTree;
use Override;

/**
 * @property int         $id
 * @property string      $uuid
 * @property int         $apps_id
 * @property int         $companies_id
 * @property int|null    $users_id
 * @property int|null    $people_id
 * @property int|null    $department_id
 * @property int|null    $position_id
 * @property int|null    $manager_employee_id
 * @property string|null $reporting_path
 * @property string|null $employee_number
 * @property string|null $description
 * @property string      $employment_type
 * @property string|null $home_entity
 * @property string      $status
 *
 * @property-read Position|null   $position
 * @property-read Department|null $department
 */
#[ObservedBy([EmployeeObserver::class])]
class Employee extends BaseModel
{
    use AsTree;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use EmitsLedgerEventsForEntity;
    use HasLightHouseCache;
    use HasMessagesTrait;
    use UuidTrait;

    protected $table = 'hr_employees';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'end_is_voluntary' => 'boolean',
        'hired_at' => 'date',
        'terminated_at' => 'date',
    ];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'HrEmployee';
    }

    protected function sourceDomainForLedger(): string
    {
        return 'HumanResources';
    }

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_employees';
    }

    // AsTree models the reporting line: parent() = manager, children() = reports.
    public function getParentKeyName(): string
    {
        return 'manager_employee_id';
    }

    public function getPathColumn(): string
    {
        return 'reporting_path';
    }

    /**
     * A one-line summary — title, department, description — an orchestrator can match work against.
     * Null when the record carries none of these, so callers fall back to matching by name.
     */
    public function describeForAssignment(): ?string
    {
        $parts = array_filter([
            trim((string) $this->position?->title),
            trim((string) $this->department?->name),
            trim((string) $this->description),
        ]);

        return $parts === [] ? null : implode(' — ', $parts);
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(SeatAssignment::class, 'employee_id');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function compensations(): HasMany
    {
        return $this->hasMany(EmployeeCompensation::class, 'employee_id');
    }

    public function currentCompensation(): HasOne
    {
        return $this->hasOne(EmployeeCompensation::class, 'employee_id')
            ->whereNull('effective_to')
            ->latestOfMany('effective_from');
    }

    /**
     * Whether this employee is somewhere up the reporting line from $employee. Shared by the leave
     * approval paths — the GraphQL resolver and the agent tool must not each carry their own idea of
     * who is allowed to approve whose time off.
     */
    public function manages(self $employee): bool
    {
        return $this->descendants()->where('id', $employee->getId())->exists();
    }

    /**
     * Relationship visibility: admins see all; an employee sees themselves + their reporting
     * subtree (AsTree descendants over manager_employee_id); non-employees see nothing.
     */
    public function scopeVisibleToActor(Builder $query): Builder
    {
        $user = auth()->user();
        if (! $user instanceof UserInterface) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isAdmin()) {
            return $query;
        }

        $viewer = new EmployeeIdentityResolver()->fromUser($user, $user->getCurrentCompany(), app(Apps::class));
        if ($viewer === null) {
            return $query->whereRaw('1 = 0');
        }

        $visibleIds = $viewer->descendants()->pluck('id')->push($viewer->getId())->all();

        return $query->whereIn('id', $visibleIds);
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_hr_employee_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'hr_employee_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'employee_number' => $this->employee_number,
            'description' => $this->description,
            'employment_type' => $this->employment_type,
            'status' => $this->status,
            'home_entity' => $this->home_entity,
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
                ['name' => 'employee_number', 'type' => 'string', 'optional' => true],
                ['name' => 'description', 'type' => 'string', 'optional' => true],
                ['name' => 'employment_type', 'type' => 'string', 'optional' => true],
                ['name' => 'status', 'type' => 'string', 'optional' => true],
                ['name' => 'home_entity', 'type' => 'string', 'optional' => true],
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
            $query->options(['query_by' => 'employee_number,description,employment_type,status,home_entity']);
        }

        return $query;
    }
}
