<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Baka\Support\Str;
use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\Actions\UpdateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products as ProductsModel;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ProductSimpleMutation
{
    /**
     * Create a product with variants and quantities.
     * Automatically adds variants to default warehouse with specified quantities.
     */
    public function create(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ProductsModel
    {
        $input = $args['input'];
        $user = auth()->user();
        $app = app(Apps::class);

        if ($user->isAppOwner() && isset($input['company_id'])) {
            $company = Companies::getById($input['company_id']);
        } else {
            $company = $user->getCurrentCompany();
        }

        $this->resolveProductType($input, $app, $company, $user);

        // Handle status if provided
        if (isset($input['status'])) {
            $input['status_id'] = StatusRepository::getById(
                (int) $input['status']['id'],
                $user->getCurrentCompany()
            )->getId();
        }

        if (isset($input['variants'])) {
            foreach ($input['variants'] as $index => &$variant) {
                // Handle channels: findOrCreate channel by name and convert to channels_id
                if (isset($variant['channels'])) {
                    $variant['channels'] = $this->processChannels($variant['channels'], $company, $app, $user);
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
     */
    public function update(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): ProductsModel
    {
        $input = $args['input'];
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Get the existing product
        $product = ProductsRepository::getById((int) $args['id'], $company);

        $this->resolveProductType($input, $app, $company, $user);

        // Handle status if provided
        if (isset($input['status'])) {
            $input['status_id'] = StatusRepository::getById(
                (int) $input['status']['id'],
                $company
            )->getId();
        }

        if (isset($input['variants'])) {
            foreach ($input['variants'] as $index => &$variant) {
                // Handle channels: findOrCreate channel by name and convert to channels_id
                if (isset($variant['channels'])) {
                    $variant['channels'] = $this->processChannels($variant['channels'], $company, $app, $user);
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

    /**
     * Process channels array: findOrCreate channels by name and format for VariantService.
     */
    private function processChannels(array $channels, Companies $company, Apps $app, $user): array
    {
        $processedChannels = [];

        foreach ($channels as $channelData) {
            // FindOrCreate channel by name
            $channel = Channels::firstOrCreate(
                [
                    'name' => $channelData['name'],
                    'companies_id' => $company->getId(),
                    'apps_id' => $app->getId(),
                ],
                [
                    'slug' => $channelData['slug'] ?? Str::slug($channelData['name']),
                    'description' => $channelData['name'] . ' pricing channel',
                    'users_id' => $user->getId(),
                    'is_published' => 1,
                ]
            );

            // Get default warehouse if not specified
            $warehouseId = $channelData['warehouses_id'] ?? Warehouses::getDefault($company, $app)->getId();

            // Format for VariantService (expects channels_id, not name)
            $processedChannels[] = [
                'channels_id' => $channel->getId(),
                'warehouses_id' => $warehouseId,
                'price' => $channelData['price'],
                'discounted_price' => $channelData['discounted_price'] ?? 0,
                'is_published' => $channelData['is_published'] ?? true,
                'config' => $channelData['config'] ?? null,
            ];
        }

        return $processedChannels;
    }

    /**
     * Resolve product type by name: find existing or create new one.
     */
    private function resolveProductType(array &$input, Apps $app, Companies $company, $user): void
    {
        if (! isset($input['products_types']) || isset($input['products_types_id'])) {
            return;
        }

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
}
