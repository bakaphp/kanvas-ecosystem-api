<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\OrderTypes;

class OrderTypeMutation
{
    public function create(mixed $root, array $request): OrderTypes
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        $orderType = OrderTypes::firstOrCreate([
            'apps_id' => $app->getId(),
            'name' => $input['name'],
        ], [
            'companies_id' => $company->getId(),
        ]);

        if (! $orderType->wasRecentlyCreated) {
            throw new ValidationException('An order type with this name already exists');
        }

        return $orderType;
    }

    public function update(mixed $root, array $request): OrderTypes
    {
        $app = app(Apps::class);
        $input = $request['input'];

        $orderType = OrderTypes::where([
            'apps_id' => $app->getId(),
            'id' => $request['id'],
            'is_deleted' => 0,
        ])->first();

        if (! $orderType) {
            throw new ValidationException('Order type not found');
        }

        if (isset($input['name']) && $input['name'] !== $orderType->name) {
            $duplicate = OrderTypes::where([
                'apps_id' => $app->getId(),
                'name' => $input['name'],
                'is_deleted' => 0,
            ])
                ->where('id', '!=', $orderType->getId())
                ->exists();

            if ($duplicate) {
                throw new ValidationException('An order type with this name already exists');
            }
        }

        $orderType->update($input);

        return $orderType;
    }

    public function delete(mixed $root, array $request): bool
    {
        $app = app(Apps::class);

        $orderType = OrderTypes::where([
            'apps_id' => $app->getId(),
            'id' => $request['id'],
            'is_deleted' => 0,
        ])->first();

        if (! $orderType) {
            throw new ValidationException('Order type not found');
        }

        $hasOrders = $orderType->orders()
            ->where('is_deleted', 0)
            ->exists();

        if ($hasOrders) {
            throw new ValidationException('Cannot delete order type that has existing orders');
        }

        $orderType->delete();

        return true;
    }
}
