<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Traits\DefaultTraits;

/**
 * Class Warehouses.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $apps_id
 * @property int $regions_id
 * @property string $uuid
 * @property string $name
 * @property string $location
 * @property bool $is_default
 * @property int $is_published
 * @property int $is_deleted
 * @property string $created_at
 * @property string $updated_at
 */

class Warehouses extends BaseModel
{
    use UuidTrait;
    use DefaultTraits;

    protected $table = 'warehouses';

    protected $guarded = [];

    /**
     * Get the companies that owns the Warehouses.
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     *
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     *
     */
    public function regions(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'regions_id');
    }

    /**
     * quantityAttribute.
     */
    public function quantity(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->quantity,
        );
    }

    /**
     * price.
     */
    public function price(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->price,
        );
    }

    /**
     * sku.
     */
    public function sku(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->sku,
        );
    }

    /**
     * position.
     *
     * @return Attributre
     */
    public function position(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->position,
        );
    }

    public function serialNumber(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->serial_number,
        );
    }

    public function isOversellable(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->is_oversellable,
        );
    }

    public function isVariantDefault(): ?Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->is_default,
        );
    }

    public function isBestSeller(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->is_best_seller ?? 0,
        );
    }

    public function isOnSale(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->is_on_sale ?? 0,
        );
    }

    public function isOnPromo(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->is_on_promo ?? 0,
        );
    }

    public function canPreOrder(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->can_pre_order ?? 0,
        );
    }

    public function isComingSoon(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->is_coming_soon ?? 0,
        );
    }

    public function isNew(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->is_new ?? 0,
        );
    }

    public function isVariantPublished(): ?Attribute
    {
        return Attribute::make(
            get: fn () => $this->pivot->is_published
        );
    }
}
