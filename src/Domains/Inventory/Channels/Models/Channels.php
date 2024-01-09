<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Users\Models\Users;

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

    protected $table = 'channels';
    protected $guarded = [];

    /**
     * Get the companies that owns the Warehouses.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * users.
     *
     * @return BelongsTo
     */
    public function users(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Get the user's first name.
     *
     * @return Attribute
     */
    protected function warehousesId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->warehouses_id,
        );
    }

    /**
     * Discounts.
     *
     * @return Attribute
     */
    protected function discountedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->discounted_price,
        );
    }

    /**
     * Get the user's first name.
     *
     * @return Attribute
     */
    protected function price(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->price,
        );
    }

    /**
     * Get the user's first name.
     *
     * @return Attribute
     */
    protected function isPublished(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->is_published,
        );
    }

    /**
     * Available products in this channel
     *
     * @return HasMany
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
     *
     * @return bool
     */
    public function unPublishAllVariants(): bool
    {
        return $this->availableProducts()->update(['is_published' => 0]) > 0;
    }

    public function pricesHistory(): HasMany
    {
        return $this->hasMany(
            VariantChannelPriceHistory::class,
            'channels_id'
        );
    }
}
