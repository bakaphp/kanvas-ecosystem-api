<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Bundles;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Bundles\Actions\CreateBundleAction;
use Kanvas\Inventory\Bundles\Actions\UpdateBundleAction;
use Kanvas\Inventory\Bundles\DataTransferObject\Bundle;
use Kanvas\Inventory\Bundles\Models\Bundle as Bundles;
use Kanvas\Inventory\Variants\Models\Variants;

class BundleManagementMutation
{
    public function create(mixed $root, array $request): Bundles
    {
        $input = $request['input'];
        $variant = isset($input['variant']) ? Variants::getById($input['variant_id'], app(Apps::class)) : null;
        $dto = Bundle::from([
            'app' => app(Apps::class),
            'company' => auth()->user()->getCurrentCompany(),
            'user' => auth()->user(),
            'name' => $input['name'],
            'variant' => $variant,
            'description' => $input['description'] ?? null,
            'execution_mode' => $input['execution_mode'] ?? 'manual',
            'expose_as_product' => $input['expose_as_product'] ?? false,
            'variants' => $input['variants'] ?? [],
        ]);

        return (new CreateBundleAction($dto))->execute();
    }

    public function update(mixed $root, array $request): Bundles
    {
        $input = $request['input'];
        $bundle = Bundles::getById($input['id'], app(Apps::class));
        $variant = isset($input['variant']) ? Variants::getById($input['variant_id'], app(Apps::class)) : null;
        $dto = Bundle::from([
            'app' => app(Apps::class),
            'company' => auth()->user()->getCurrentCompany(),
            'user' => auth()->user(),
            'name' => $input['name'],
            'variant' => $variant,
            'description' => $input['description'] ?? null,
            'execution_mode' => $input['execution_mode'] ?? 'manual',
            'expose_as_product' => $input['expose_as_product'] ?? false,
            'variants' => $input['variants'] ?? [],
        ]);

        return (new UpdateBundleAction($bundle, $dto))->execute();
    }
}
