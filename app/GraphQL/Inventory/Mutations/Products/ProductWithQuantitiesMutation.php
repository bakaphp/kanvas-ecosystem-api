<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Baka\Support\Str;
use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\Actions\UpdateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products as ProductsModel;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Services\VariantService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ProductWithQuantitiesMutation
{
    /**
     * Create a product with variants and quantities.
     * Automatically adds variants to default warehouse with specified quantities.
     *
     * @param mixed $root
     * @param array{input: array} $args
     */
    public function create(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ProductsModel
    {
        $input = $args['input'];
        $user = auth()->user();
        $app = app(Apps::class);

        // Handle products_types lookup by name if provided (creates if not exists)
        if (isset($input['products_types']) && ! isset($input['products_types_id'])) {
            $productType = ProductsTypes::firstOrCreate(
                [
                    'name' => $input['products_types'],
                    'apps_id' => $app->getId(),
                ],
                [
                    'companies_id' => $user->getCurrentCompany()->getId(),
                    'users_id' => $user->getId(),
                    'slug' => Str::slug($input['products_types']),
                    'description' => $input['products_types'] . ' product type',
                    'weight' => 0,
                ]
            );
            $input['products_types_id'] = $productType->getId();
            unset($input['products_types']);
        }

        // Handle status if provided
        if (isset($input['status'])) {
            $input['status_id'] = StatusRepository::getById(
                (int) $input['status']['id'],
                $user->getCurrentCompany()
            )->getId();
        }

        // Handle company (for app owners)
        if ($user->isAppOwner() && isset($input['company_id'])) {
            $company = Companies::getById($input['company_id']);
        } else {
            $company = $user->getCurrentCompany();
        }

        // Transform variants to include quantity and auto-generate SKU if needed
        if (isset($input['variants'])) {
            foreach ($input['variants'] as $index => &$variant) {
                // Auto-generate SKU from product slug + variant name if not provided
                if (empty($variant['sku'])) {
                    $productSlug = $input['slug'] ?? Str::slug($input['name']);
                    $variantSlug = Str::slug($variant['name']);
                    $variant['sku'] = $productSlug . '_' . $variantSlug;
                }

                // Quantity will be automatically handled by VariantService::createVariantsFromArray
                // It checks for 'quantity' in the variant array and passes it to the warehouse
            }
        }

        // Create product using standard DTO and Action
        $productDto = ProductDto::viaRequest($input, $company);
        $action = new CreateProductAction($productDto, $user);

        return $action->execute();
    }

    /**
     * Update a product with variants and quantities.
     * Updates existing variants or creates new ones in default warehouse.
     *
     * @param mixed $root
     * @param array{id: string, input: array} $args
     */
    public function update(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ProductsModel
    {
        $input = $args['input'];
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Get the existing product
        $product = ProductsRepository::getById((int) $args['id'], $company);

        // Handle products_types lookup by name if provided (creates if not exists)
        if (isset($input['products_types']) && ! isset($input['products_types_id'])) {
            $productType = ProductsTypes::firstOrCreate(
                [
                    'name' => $input['products_types'],
                    'apps_id' => $app->getId(),
                ],
                [
                    'companies_id' => $user->getCurrentCompany()->getId(),
                    'users_id' => $user->getId(),
                    'slug' => Str::slug($input['products_types']),
                    'description' => $input['products_types'] . ' product type',
                    'weight' => 0,
                ]
            );
            $input['products_types_id'] = $productType->getId();
            unset($input['products_types']);
        }

        // Handle status if provided
        if (isset($input['status'])) {
            $input['status_id'] = StatusRepository::getById(
                (int) $input['status']['id'],
                $company
            )->getId();
        }

        // Transform variants to include quantity and auto-generate SKU if needed
        if (isset($input['variants'])) {
            foreach ($input['variants'] as $index => &$variant) {
                // Auto-generate SKU from product slug + variant name if not provided
                if (empty($variant['sku'])) {
                    $productSlug = $input['slug'] ?? $product->slug;
                    $variantSlug = Str::slug($variant['name']);
                    $variant['sku'] = $productSlug . '_' . $variantSlug;
                }
            }

            // Process variants separately after product update
            $variantsInput = $input['variants'];
            unset($input['variants']);
        }

        // Update product using standard DTO and Action
        $productDto = ProductDto::viaRequest($input, $product->company);
        $productModel = (new UpdateProductAction($product, $productDto, $user))->execute();

        // Process variants if provided
        if (isset($variantsInput)) {
            VariantService::createVariantsFromArray($productModel, $variantsInput, $user);
        }

        return $productModel->refresh();
    }
}
