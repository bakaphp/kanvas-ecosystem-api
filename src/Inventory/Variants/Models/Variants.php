<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Interactions\Traits\SocialInteractionsTrait;
use Laravel\Scout\Searchable;

/**
 * Class Attributes.
 *
 * @property int apps_id
 * @property int companies_id
 * @property int products_id
 * @property string uuid
 * @property string name
 * @property string slug
 * @property string description
 * @property string short_description
 * @property string html_description
 * @property string sku
 * @property string ean
 * @property string barcode
 * @property string serial_number
 * @property bool is_published
 */
class Variants extends BaseModel
{
    use SlugTrait;
    use UuidTrait;
    use Searchable;
    use SocialInteractionsTrait;

    protected $table = 'products_variants';
    protected $guarded = [];
    protected static ?string $overWriteSearchIndex = null;

    /**
      * Get the name of the index associated with the model.
      */
    public function searchableAs(): string
    {
        return (!isset($this->companies_id) || $this->companies_id === null) && self::$overWriteSearchIndex !== null
            ? self::$overWriteSearchIndex
            : 'products_variants_company_' . (string) $this->companies_id;
    }

    /**
     * Overwrite the search index when calling the method via static methods
     */
    public static function setSearchIndex(int $companyId): void
    {
        self::$overWriteSearchIndex = 'products_variants_company_' . $companyId;
    }

    /**
     * Get the user that owns the Variants.
     *
     * @return BelongsTo
     */
    public function products(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'products_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'products_id');
    }

    /**
     * The warehouses that belong to the Variants.
     *
     * @return BelongsToMany
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(
            Warehouses::class,
            'products_variants_warehouses',
            'products_variants_id',
            'warehouses_id'
        )
            ->withPivot(
                'quantity',
                'price',
                'sku',
                'position',
                'serial_number',
                'is_oversellable',
                'is_default',
                'is_default',
                'is_best_seller',
                'is_on_sale',
                'is_on_promo',
                'can_pre_order',
                'is_new',
                'is_published'
            );
    }

    /**
     * attributes.
     *
     * @return BelongsToMany
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(
            Attributes::class,
            VariantsAttributes::class,
            'products_variants_id',
            'attributes_id'
        )
            ->withPivot('value');
    }

    /**
     * channels.
     *
     * @return BelongsToMany
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(
            Channels::class,
            VariantsChannels::class,
            'products_variants_id',
            'channels_id'
        )
            ->withPivot(
                'price',
                'discounted_price',
                'is_published',
                'warehouses_id'
            );
    }
}
