<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Traits\DefaultTrait;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;

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
        Variants::fromCompany($this->company)->chunkById(100, function ($variants) {
            $variants->unsearchable();
        }, $column = 'id');

        return $this->availableProducts()->update(['is_published' => 0]) > 0;
    }

    public function pricesHistory(): HasMany
    {
        return $this->hasMany(
            VariantChannelPriceHistory::class,
            'channels_id'
        );
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
            get: fn () => $this->pivot->is_published,
        );
    }

    public function warehousesId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->warehouses_id,
        );
    }
}
