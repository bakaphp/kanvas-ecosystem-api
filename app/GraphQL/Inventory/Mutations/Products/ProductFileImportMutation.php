<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Facades\Validator;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\ProductsTypes\Actions\ResolveProductTypeAction;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;

class ProductFileImportMutation
{
    use ResolvesActingContext;

    public function import(mixed $root, array $request): FilesystemImports
    {
        $input = $request['input'];
        Validator::make($input, [
            'filesystem_id' => ['required', 'integer'],
            'mapping' => ['required', 'array'],
            'mapping.handler' => ['required'],
            'mapping.product_name' => ['required'],
            'mapping.variant_name' => ['required'],
            'mapping.sku' => ['required'],
            'configuration' => ['required', 'array:product_type_id,products_types,channels_id,warehouse_id'],
            'configuration.product_type_id' => ['required_without:configuration.products_types', 'integer', 'min:1'],
            'configuration.products_types' => ['required_without:configuration.product_type_id', 'string', 'max:255'],
            'configuration.channels_id' => ['sometimes', 'integer', 'min:1'],
            'configuration.warehouse_id' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        $context = $this->actingContext();
        $filesystem = Filesystem::getByIdFromCompanyApp((int) $input['filesystem_id'], $context->company, $context->app);

        if (isset($input['configuration']['channels_id'])) {
            Channels::getByIdFromCompanyApp((int) $input['configuration']['channels_id'], $context->company, $context->app);
        }
        if (isset($input['configuration']['warehouse_id'])) {
            Warehouses::getByIdFromCompanyApp((int) $input['configuration']['warehouse_id'], $context->company, $context->app);
        }

        $type = new ResolveProductTypeAction($context->app, $context->company, $context->user)->execute(
            id: isset($input['configuration']['product_type_id']) ? (int) $input['configuration']['product_type_id'] : null,
            name: $input['configuration']['products_types'] ?? null,
        );
        $input['configuration']['product_type_id'] = $type->getId();
        unset($input['configuration']['products_types']);

        return FilesystemImports::create([
            'apps_id' => $context->app->getId(),
            'companies_id' => $context->company->getId(),
            'companies_branches_id' => $context->user->getCurrentBranch()->getId(),
            'users_id' => $context->user->getId(),
            'regions_id' => Regions::getDefault($context->company, $context->app)->getId(),
            'filesystem_id' => $filesystem->getId(),
            'filesystem_mapper_id' => null,
            'status' => 'pending',
            'extra' => ['inline_product_import' => [
                'mapping' => $input['mapping'],
                'configuration' => $input['configuration'],
            ]],
        ]);
    }
}
