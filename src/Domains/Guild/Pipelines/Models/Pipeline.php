<?php

declare(strict_types=1);

namespace Kanvas\Guild\Pipelines\Models;

use Baka\Traits\SlugTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Models\BaseModel;

/**
 * Class Pipeline.
 *
 * @property int $id
 * @property int|null $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $system_modules_id
 * @property string $name
 * @property string $slug
 * @property int $weight
 * @property int $is_default
 */
class Pipeline extends BaseModel
{
    use SlugTrait;

    protected $table = 'pipelines';
    protected $guarded = [];
    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'pipelines_id', 'id')->orderBy('weight', 'ASC');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'pipeline_id', 'id');
    }

    public function switchDefaultPipeline(): void
    {
        $this->getConnection()->transaction(function () {
            $this->update(['is_default' => 1]);
            self::where('id', '!=', $this->id)
                ->where('companies_id', $this->companies_id)
                ->update(['is_default' => 0]);
        });
    }

    public function isDefault(): bool
    {
        return (bool) $this->is_default;
    }
}
