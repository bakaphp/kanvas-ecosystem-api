<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class ProductsTypesRepository
{
    use SearchableTrait;

    public static function getModel(): Model
    {
        return new ProductsTypes();
    }

    /**
     * Resolve a product type from a `FilesystemMapper.configuration.product_type_id` style value,
     * which arrives as whatever JSON held — null, '', 0 or an id.
     *
     * Product types own the attribute schema for the products imported under them, so without one
     * the attributes have no type to attach to and the imported products are data-orphans. Fail
     * before any record is processed rather than half-importing.
     */
    public static function getFromConfiguredId(
        mixed $productTypeId,
        CompanyInterface $company,
        ?AppInterface $app = null
    ): ProductsTypes {
        if ($productTypeId === null || $productTypeId === '' || $productTypeId === 0) {
            throw new ValidationException(
                'Product imports require configuration.product_type_id on the FilesystemMapper. '
                . 'Without it, imported products cannot be associated with their product type, '
                . 'breaking attribute associations. Set product_type_id on the mapper before importing.'
            );
        }

        /** @var ProductsTypes $productType */
        $productType = self::getByIdOrGlobal((int) $productTypeId, $company, $app);

        return $productType;
    }

    /**
     * getBySourceId.
     *
     * @param  mixed $id
     */
    public static function getBySourceKey(string $key, string $id): ProductsTypes
    {
        $key = $key . '_id';

        return ProductsTypes::getByCustomField($key, $id);
    }
}
