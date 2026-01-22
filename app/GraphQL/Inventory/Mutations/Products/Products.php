<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Baka\Support\Str;
use Illuminate\Auth\Access\AuthorizationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\Actions\DuplicateProductAction;
use Kanvas\Inventory\Products\Actions\RemoveAttributeAction;
use Kanvas\Inventory\Products\Actions\UpdateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\DataTransferObject\Translate as ProductTranslateDto;
use Kanvas\Inventory\Products\Models\Products as ProductsModel;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\Products\Models\ProductsWarehouses;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Languages\Models\Languages;

class Products
{
    /**
     * create.
     * @todo allow to search only companies with access to the app
     */
    public function create(mixed $root, array $req): ProductsModel
    {
        $input = $req['input'];
        $user = auth()->user();
        $app = app(Apps::class);

        if (isset($input['status'])) {
            $input['status_id'] = StatusRepository::getById(
                (int) $input['status']['id'],
                $user->getCurrentCompany()
            )->getId();
        }

        if ($user->isAppOwner() && isset($input['company_id'])) {
            $company = Companies::getById($input['company_id']);
        } else {
            $company = $user->getCurrentCompany();
        }

        // Handle products_types lookup by name if provided (creates if not exists)
        if (isset($input['products_types']) && ! isset($input['products_types_id'])) {
            $productType = ProductsTypes::firstOrCreate(
                [
                    'slug' => Str::slug($input['products_types']),
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                ],
                [
                    'name' => $input['products_types'],
                    'users_id' => $user->getId(),
                    'description' => $input['products_types'] . ' product type',
                    'weight' => 0,
                ]
            );
            $input['products_types_id'] = $productType->getId();
            unset($input['products_types']);
        }

        // Auto-generate SKU for variants if not provided
        if (isset($input['variants'])) {
            $productSlug = $input['slug'] ?? Str::slug($input['name']);
            foreach ($input['variants'] as $index => &$variant) {
                if (empty($variant['sku'])) {
                    $variantSlug = Str::slug($variant['name']);
                    $variant['sku'] = $productSlug . '_' . $variantSlug;
                }
            }
            unset($variant);
        }

        $productDto = ProductDto::from(
            request: $input,
            company: $company,
            user: $user,
            app: $app
        );
        $action = new CreateProductAction($productDto, $user);
        $action = new CreateProductAction(
            $productDto,
            $user
        );

        return $action->execute();
    }

    /**
     * update.
     */
    public function update(mixed $root, array $req): ProductsModel
    {
        $input = $req['input'];
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $product = ProductsRepository::getById((int) $req['id'], $company);

        if (isset($input['status'])) {
            $input['status_id'] = StatusRepository::getById((int) $input['status']['id'], $company)->getId();
        }

        // Handle products_types lookup by name if provided (creates if not exists)
        if (isset($input['products_types']) && ! isset($input['products_types_id'])) {
            $productType = ProductsTypes::firstOrCreate(
                [
                    'slug' => Str::slug($input['products_types']),
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                ],
                [
                    'name' => $input['products_types'],
                    'users_id' => $user->getId(),
                    'description' => $input['products_types'] . ' product type',
                    'weight' => 0,
                ]
            );
            $input['products_types_id'] = $productType->getId();
            unset($input['products_types']);
        }

        // Auto-generate SKU for variants if not provided
        if (isset($input['variants'])) {
            $productSlug = $input['slug'] ?? $product->slug;
            foreach ($input['variants'] as &$variant) {
                if (empty($variant['sku'])) {
                    $variantSlug = Str::slug($variant['name']);
                    $variant['sku'] = $productSlug . '_' . $variantSlug;
                }
            }
            unset($variant);

            // Process variants separately after product update
            $variantsInput = $input['variants'];
            unset($input['variants']);
        }

        $productDto = ProductDto::from(
            request: $input,
            company: $product->company,
            user: $user,
            app: $app
        );
        $productModel = new UpdateProductAction(
            $product,
            $productDto,
            $user
        )->execute();

        // Process variants if provided
        if (isset($variantsInput)) {
            VariantService::createVariantsFromArray($productModel, $variantsInput, $user);
        }

        return $productModel->refresh();
    }

    /**
     * delete.
     */
    public function delete(mixed $root, array $req): bool
    {
        $product = ProductsRepository::getById(
            (int) $req['id'],
            auth()->user()->getCurrentCompany()
        );

        Variants::withoutEvents(function () use ($product) {
            foreach ($product->variants as $variant) {
                $variant->delete();
            }
        });

        return $product->delete();
    }

    /**
     * addAttribute.
     */
    public function addAttribute(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById(
            (int) $req['id'],
            auth()->user()->getCurrentCompany()
        );
        $attribute = AttributesRepository::getById(
            (int) $req['attribute_id'],
            auth()->user()->getCurrentCompany()
        );
        $action = new AddAttributeAction(
            $product,
            $attribute,
            $req['value']
        );

        return $action->execute();
    }

    /**
     * removeAttribute.
     */
    public function removeAttribute(mixed $root, array $req): ProductsModel
    {
        $app = app(Apps::class);
        $product = ProductsRepository::getById(
            (int) $req['id'],
            auth()->user()->getCurrentCompany(),
            $app
        );
        $attribute = AttributesRepository::getById(
            (int) $req['attribute_id'],
            auth()->user()->getCurrentCompany()
        );
        $action = new RemoveAttributeAction(
            $product,
            $attribute
        );

        return $action->execute();
    }

    /**
     * addWarehouse.
     */
    public function addWarehouse(mixed $root, array $req): ProductsModel
    {
        $app = app(Apps::class);
        $product = ProductsRepository::getById(
            (int) $req['id'],
            auth()->user()->getCurrentCompany(),
            $app
        );

        $productWarehouse = ProductsWarehouses::where('products_id', $product->getId())
           ->where('warehouses_id', $req['warehouse_id'])
           ->first();

        if ($productWarehouse === null) {
            $product->warehouses()->attach($req['warehouse_id']);
        }

        return $product;
    }

    /**
     * removeWarehouse.
     */
    public function removeWarehouse(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById(
            (int) $req['id'],
            auth()->user()->getCurrentCompany()
        );
        $product->warehouses()->detach($req['warehouse_id']);

        return $product;
    }

    /**
     * addCategory.
     */
    public function addCategory(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById(
            (int) $req['id'],
            auth()->user()->getCurrentCompany()
        );
        $product->categories()->attach($req['category_id']);

        return $product;
    }

    /**
     * removeCategory.
     */
    public function removeCategory(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById(
            (int) $req['id'],
            auth()->user()->getCurrentCompany()
        );
        $product->categories()->detach($req['category_id']);

        return $product;
    }

    /**
     * update.
     */
    public function updateProductTranslation(mixed $root, array $req): ProductsModel
    {
        $company = auth()->user()->getCurrentCompany();
        $language = Languages::getByCode($req['code']);

        $product = ProductsRepository::getById(
            (int) $req['id'],
            $company
        );
        $productTranslateDto = ProductTranslateDto::fromMultiple(
            $req['input'],
            $product->company
        );

        foreach ($productTranslateDto->toArray() as $key => $value) {
            $product->setTranslation($key, $language->code, $value);
            $product->save();
        }

        return $product;
    }

    public function updateProductAttributeTranslation(mixed $root, array $req): ProductsAttributes
    {
        $company = auth()->user()->getCurrentCompany();
        $language = Languages::getByCode($req['code']);
        $attribute = AttributesRepository::getById(
            (int) $req['attribute_id'],
            $company
        );
        $product = ProductsRepository::getById(
            (int) $req['product_id'],
            $company
        );

        $productAttribute = $product->attributeValues(
            'attribute_id',
            $attribute->getId()
        )->firstOrFail();
        $value = $req['value'];
        $productAttribute->setTranslation(
            'value',
            $language->code,
            $value
        );
        $productAttribute->save();

        return $productAttribute;
    }

    public function duplicateProduct(mixed $root, array $req): ProductsModel
    {
        $company = auth()->user()->getCurrentCompany();

        $product = ProductsRepository::getById(
            (int) $req['id'],
            $company
        );
        $productModel = (new DuplicateProductAction(
            $product,
            auth()->user()
        ))->execute();

        return $productModel;
    }

    public function publishManagement(mixed $root, array $req): ProductsModel
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        if (! $user->can('is_published', ProductsModel::class) || ! $user->isAdmin()) {
            throw new AuthorizationException('You are not allowed to perform this action');
        }

        $product = ProductsRepository::getById(
            (int) $req['id'],
            $company
        );

        if ($req['is_published']) {
            $product->publish();
            $product->searchable();
        } else {
            $product->unPublish();
            $product->unsearchable();
        }

        return $product;
    }
}
