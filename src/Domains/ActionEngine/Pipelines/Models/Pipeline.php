<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Models\BaseModel;
use Kanvas\Apps\Models\Apps;

/**
 * Class Pipeline.
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $slug
 * @property string $name
 * @property int $weight
 */
class Pipeline extends BaseModel
{
    use UuidTrait;
    use DatabaseSearchableTrait;

    protected $table = 'pipelines';
    protected $guarded = [];

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'pipelines_id', 'id');
    }

    public function companyActions(): HasMany
    {
        return $this->hasMany(CompanyAction::class, 'pipelines_id', 'id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class, 'pipelines_id', 'id');
    }

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_pipeline_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'pipeline_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->is_deleted == 0;
    }

    public static function getBySlug(string $slug, AppInterface $app, CompanyInterface $company): self
    {
        return self::query()
            ->where('slug', $slug)
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->notDeleted()
            ->firstOrFail();
    }
}
