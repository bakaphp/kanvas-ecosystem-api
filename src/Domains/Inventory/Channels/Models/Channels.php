<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Models;

use Baka\Support\Str;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Traits\DefaultTrait;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
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

    /**
     * Update all variants doesn't matter the location from this channel
     */
    public function unPublishAllVariants(): bool
    {
        $dontUnPublishVariantsId = $this->company->get('dont_unpublish_variants', []);

        // Get all variant IDs that need to be unpublished from this channel
        $query = $this->availableProducts();

        if (! empty($dontUnPublishVariantsId)) {
            $query->whereNotIn('products_variants_id', $dontUnPublishVariantsId);
        }

        // Get variant IDs in a single query
        $variantIds = $query->pluck('products_variants_id')->unique()->toArray();

        if (! empty($variantIds)) {
            // Remove from search index efficiently - get all variants at once
            $variants = Variants::whereIn('id', $variantIds)->get();
            $variants->unsearchable();
        }

        // Update all channel products in a single query
        return $query->update(['is_published' => 0]) > 0;
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
            get: fn () => $this->pivot->price,
        );
    }

    public function discountedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->discounted_price,
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
        return $this->productVariantChannels()
            ->with('warehouse.region')
            ->get()
            ->pluck('warehouse.region')
            ->whereNotNull()
            ->unique('id')
            ->values();
    }
}
