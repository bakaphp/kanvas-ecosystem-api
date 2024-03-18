<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAttributeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypesAttributes as ProductsTypesAttributesDto;
use Kanvas\Inventory\Traits\ScopesTrait;

/**
 * Class ProductsTypes.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $users_id
 * @property int $companies_id
 * @property string $name
 * @property string $uuid
 * @property string $slug
 * @property string $description
 * @property int $weight
 */
class ProductsTypes extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use ScopesTrait;
    use CascadeSoftDeletes;

    protected $table = 'products_types';
    protected $fillable = [
        'name',
        'uuid',
        'description',
        'weight',
        'slug',
        'is_published'
    ];
    protected $guarded = [];

    /**
     * Get the user that owns the ProductsTypes.
     *
     * @return BelongsTo
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Products::class, 'products_types_id');
    }

    public function productsTypesAttributes(): HasMany
    {
        return $this->hasMany(ProductsTypesAttributes::class, 'products_Types_id');
    }

    /**
     * attributes.
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(
            Attributes::class,
            ProductsTypesAttributes::class,
            'products_types_id',
            'attributes_id'
        );
    }

    /**
     * Get the total amount of products of a product type.
     *
     * @return Int
     */
    public function getTotalProducts(): int
    {
        if (! $totalProducts = $this->get('total_products')) {
            return (int) $this->setTotalProducts();
        }
        return (int) $totalProducts;
    }

    /**
     * Set the total amount of products of a product type.
     *
     * @return Int
     */
    public function setTotalProducts(): int
    {
        $total = Products::where('products_types_id', $this->getId())
                ->where('is_deleted', 0)
                ->count();

        $this->set('total_products', $total);

        return (int) $total;
    }

    /**
     * Add a new attribute to a product type.
     *
     * @param UserInterface $user
     * @param array $attributes
     * @param boolean $toVariant
     * @return void
     */
    public function addAttributes(UserInterface $user, array $attributes, bool $toVariant = false): void
    {
        foreach ($attributes as $attribute) {
            $productsAttributesDto = ProductsTypesAttributesDto::viaRequest([
                'product_type' => $this,
                'attribute' => Attributes::getById((int) $attribute['id']),
                'toVariant' => $toVariant
            ]);

            (new CreateProductTypeAttributeAction($productsAttributesDto, $user))->execute();
        }
    }

    /**
     * Get all the products attributes from the product type
     *
     * @return array
     */
    public function getProductsAttributes(): array
    {
        $attributes = $this->attributes()
                            ->where('to_variants', 0)
                            ->where('products_types_attributes.is_deleted', 0)
                            ->get();

        return $attributes->toArray();
    }

    /**
     * Get all the variants attributes from the product type
     *
     * @return array
     */
    public function getVariantsAttributes(): array
    {
        $attributes = $this->attributes()
                            ->where('to_variants', 1)
                            ->where('products_types_attributes.is_deleted', 0)
                            ->get();

        return $attributes->toArray();
    }
}
