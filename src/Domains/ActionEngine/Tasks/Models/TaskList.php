<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Tasks\Models;

use Baka\Casts\Json;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\ActionEngine\Tasks\Factories\TaskListFactory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Override;

/**
 * Class Tasks.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $config
 */
class TaskList extends BaseModel
{
    use UuidTrait;
    use DatabaseSearchableTrait;

    protected $table = 'company_task_list';
    protected $guarded = [];

    protected $casts = [
        'config' => Json::class,
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(TaskListItem::class, 'task_list_id')->orderBy('weight');
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_task_list_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'task_list_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! auth()->user()->isAppOwner()) {
            $query->where('companies_id', auth()->user()->getCurrentCompany()->getId());
        }

        return $query;
    }

    #[Override]
    public static function newFactory()
    {
        return TaskListFactory::new();
    }
}
