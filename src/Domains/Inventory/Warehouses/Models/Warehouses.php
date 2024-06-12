<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Traits\DatabaseSearchableTrait;
use Kanvas\Inventory\Traits\DefaultTrait;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

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
    use DefaultTrait;
    use DatabaseSearchableTrait;

    protected $table = 'warehouses';

    protected $guarded = [];

    /**
     * @deprecated use app
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * @deprecated
     */
    public function regions(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'regions_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'regions_id');
    }

    public function variantsWarehouses(): HasMany
    {
        return $this->hasMany(VariantsWarehouses::class, 'warehouses_id');
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

    /**
     * Get the total amount of products of a warehouse.
     */
    public function getTotalProducts(): int
    {
        if (! $totalProducts = $this->get('total_products')) {
            return (int) $this->setTotalProducts();
        }

        return (int) $totalProducts;
    }

    /**
     * Set the total amount of products of a warehouse.
     */
    public function setTotalProducts(): int
    {
        if ($this->variantsWarehouses()->exists()) {
            $this->set(
                'total_products',
                $this->variantsWarehouses()->first()->getTotalProducts()
            );

            return (int) $this->get('total_products');
        }

        return 0;
    }
}
