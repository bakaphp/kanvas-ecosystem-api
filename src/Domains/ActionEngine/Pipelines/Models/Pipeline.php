<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Pipelines\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\ActionEngine\Models\BaseModel;

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

    protected $table = 'pipelines';
    protected $guarded = [];

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'pipelines_id', 'id');
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

    public function getStageBySlug(string $stage): PipelineStage
    {
        return $this->stages()
            ->where('slug', $stage)
            ->firstOrFail();
    }
}
