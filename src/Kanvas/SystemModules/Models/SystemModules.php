<?php

declare(strict_types=1);

namespace Kanvas\SystemModules\Models;

use Baka\Casts\Json;
use Baka\Support\Str;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Models\BaseModel;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\Database\Ability;

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
        'fields' => Json::class
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

    public static function convertLegacySystemModules(string $className): string
    {
        $mapping = [
            'Gewaer\\Models\\Leads' => Lead::class,
            'Gewaer\\Models\\Messages' => Message::class,
            'Gewaer\\Models\\Companies' => Companies::class,
            'Kanvas\\Packages\\Social\\Models\\Messages' => Message::class,
            // 'Kanvas\Guild\Activities\Models\Activities' => Message::class,
        ];

        return $mapping[$className] ?? $className;
    }

    public static function getLegacyNamespace(string $className): string
    {
        $mapping = [
            Lead::class => 'Gewaer\\Models\\Leads',
            People::class => 'Gewaer\\Models\\Peoples\\Peoples',
            Message::class => 'Gewaer\\Models\\Messages',
            Companies::class => 'Gewaer\\Models\\Companies',
            // Message::class => 'Kanvas\Packages\Social\Models\Messages',
            // Message::class => 'Kanvas\Guild\Activities\Models\Activities',
        ];

        return $mapping[$className] ?? $className;
    }

    public static function getSystemModuleNameSpaceBySlug(string $slug): string
    {
        $internalMapping = [
            'lead' => Lead::class,
            'people' => People::class,
            'message' => Message::class,
            'product' => Products::class,
            'variant' => Variants::class,
            'order' => Order::class,
            'user' => Users::class,
            'company' => Companies::class,
            'branch' => CompaniesBranches::class,
            'region' => Regions::class,
        ];

        return $internalMapping[strtolower($slug)] ?? throw new InvalidArgumentException('Entity ' . $slug . ' not found');
    }

    public static function getSlugBySystemModuleNameSpace(string $namespace): string
    {
        $internalMapping = [
            Lead::class => 'lead',
            People::class => 'people',
            Message::class => 'message',
            Products::class => 'product',
            Variants::class => 'variant',
            Order::class => 'order',
            Users::class => 'user',
            Companies::class => 'company',
            CompaniesBranches::class => 'branch',
            Regions::class => 'region',
        ];

        return $internalMapping[$namespace] ?? throw new InvalidArgumentException('Namespace ' . $namespace . ' not found');
    }

    public function abilities(): BelongsToMany
    {
        return $this->belongsToMany(
            Ability::class,
            'abilities_modules',
            'system_modules_id',
            'abilities_id'
        );
    }
}
