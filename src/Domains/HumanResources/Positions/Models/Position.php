<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Positions\Models;

use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Compensation\Models\PayBand;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Models\BaseModel;
use Override;

/**
 * @property int         $id
 * @property string      $uuid
 * @property int         $apps_id
 * @property int         $companies_id
 * @property int|null    $department_id
 * @property string      $title
 * @property string|null $level
 * @property string|null $description
 * @property bool        $is_active
 */
class Position extends BaseModel
{
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use UuidTrait;

    protected $table = 'hr_positions';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
        'is_active' => 'boolean',
    ];

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.hr_positions';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function payBands(): HasMany
    {
        return $this->hasMany(PayBand::class, 'position_id');
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_hr_position_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'hr_position_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'level' => $this->level,
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
                ['name' => 'title', 'type' => 'string', 'optional' => true],
                ['name' => 'level', 'type' => 'string', 'optional' => true],
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
            $query->options(['query_by' => 'title,level,description']);
        }

        return $query;
    }
}
