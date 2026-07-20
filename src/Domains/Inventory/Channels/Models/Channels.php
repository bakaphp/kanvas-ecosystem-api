<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Models;

use Baka\Support\Str;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Inventory\Channels\Actions\UnPublishAllVariantsAction;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Traits\DefaultTrait;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Social\Tags\Traits\HasTagsTrait;

/**
 * Class Channels.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $apps_id
 * @property int $users_id
 * @property string $uuid
 * @property string $name
 * @property string $description
 * @property string $slug
 * @property int $is_published
 * @property int $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */
class Channels extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use DatabaseSearchableTrait;
    use DefaultTrait;
    use HasTagsTrait;

    protected $table = 'channels';
    protected $guarded = [];

    /**
     * Available products in this channel
     */
    public function availableProducts(): HasMany
    {
        return $this->hasMany(
            VariantsChannels::class,
            'channels_id',
            'id'
        );
    }

    public function unPublishAllVariants(): void
    {
        new UnPublishAllVariantsAction($this)->execute();
    }

    public function pricesHistory(): HasMany
    {
        return $this->hasMany(
            VariantChannelPriceHistory::class,
            'channels_id'
        );
    }

    public function productVariantChannels(): HasMany
    {
        return $this->hasMany(VariantsChannels::class, 'channels_id');
    }

    public function price(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot ? $this->pivot->price : ($this->attributes['price'] ?? null),
        );
    }

    public function discountedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot ? $this->pivot->discounted_price : ($this->attributes['discounted_price'] ?? null),
        );
    }

    public function isPublished(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot ? $this->pivot->is_published : ($this->attributes['is_published'] ?? true),
        );
    }

    public function warehousesId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->warehouses_id,
        );
    }

    public function config(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::isJson($this->pivot->config) ? json_decode($this->pivot->config, true) : $this->pivot->config
        );
    }

    public function getRegions(): Collection
    {
        $warehousesTable = Warehouses::getTableName();
        $variantChannelsTable = VariantsChannels::getTableName();

        $regionIds = $this->productVariantChannels()
            ->select($warehousesTable . '.regions_id')
            ->join(
                $warehousesTable,
                $warehousesTable . '.id',
                '=',
                $variantChannelsTable . '.warehouses_id'
            )
            ->whereNotNull($warehousesTable . '.regions_id')
            ->where($warehousesTable . '.is_deleted', 0)
            ->distinct()
            ->pluck($warehousesTable . '.regions_id');

        if ($regionIds->isEmpty()) {
            return collect();
        }

        return Regions::query()
            ->whereIn(Regions::getTableName() . '.id', $regionIds->all())
            ->where('is_deleted', 0)
            ->get();
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'slug' => $this->slug,
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
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        return $query;
    }
}
