<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Models;

use Baka\Support\Str;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Models\BaseModel;

/**
 * SystemModules Model.
 *
 * @property string $name
 * @property string $uuid
 * @property string $slug
 * @property string $model_name
 * @property int $apps_id
 * @property int $parents_id
 * @property int $menu_order
 * @property int $show
 * @property int $use_elastic
 * @property string $browse_fields
 * @property string $bulk_actions
 * @property string $mobile_component_type
 * @property string $mobile_navigation_type
 * @property int $mobile_tab_index
 * @property int $protected
 */
class SystemModules extends BaseModel
{
    use SlugTrait;
    use Cachable;
    use UuidTrait;

    protected $table = 'system_modules';

    protected $casts = [
        'browse_fields' => 'array',
    ];

    protected $fillable = [
        'model_name',
        'name',
        'apps_id',
        'slug',
    ];

    /**
     * Boot function from laravel.
     *
     * @return void
     */
    public static function bootSlugTrait()
    {
        static::creating(function ($model) {
            $model->slug = $model->slug ?? Str::slug($model->model_name);
            $model->name = $model->name ?? $model->slug;
        });
    }

    /**
     * Apps relationship.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parents_id');
    }

    /**
     * Not deleted scope and app filter.
     */
    public function scopeFilterByApp(Builder $query): Builder
    {
        return $query->where('apps_id', app(Apps::class)->id)
                ->where('is_deleted', 0);
    }
}
