<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Cart;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Enums\ConfigurationEnum;

class CartManagementMutation
{
    public function add(mixed $root, array $request): array
    {
        $items = $request['items'];
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $cart = app('cart')->session($user->getId());
        $app = app(Apps::class);

        //@todo send warehouse via header
        $useCompanySpecificPrice = $app->get(ConfigurationEnum::COMPANY_CUSTOM_CHANNEL_PRICING->value) ?? false;

        foreach ($items as $item) {
            $variant = Variants::getByIdFromCompany($item['variant_id'], $company);

            $cart->add([
                'id' => $variant->getId(),
                'name' => $variant->name,
                'price' => $useCompanySpecificPrice ? $variant->variantChannels('uuid', $company->uuid)->firstOrFail()->price : $variant->variantWarehouses()->firstOrFail()->price, //@todo modify to use channel instead of warehouse
                'quantity' => $item['quantity'],
                'attributes' => $variant->product->attributes ? $variant->product->attributes->map(function ($attribute) {
                    return [
                        $attribute->name => $attribute->pivot->value,
                    ];
                })->collapse()->all() : [],
                //'associatedModel' => $Product,
            ]);
        }

        return $cart->getContent()->toArray();
    }

    public function update(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());

        if (! $cart->has($request['variant_id'])) {
            return [];
        }

        $cart->update($request['variant_id'], [
            'quantity' => $request['quantity'],
        ]);

        return $cart->getContent()->toArray();
    }

    public function remove(mixed $root, array $request): array
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());

        $cart->remove($request['variant_id']);

        return $cart->getContent()->toArray();
    }

    public function clear(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $cart = app('cart')->session($user->getId());

        return $cart->clear();
    }
}
