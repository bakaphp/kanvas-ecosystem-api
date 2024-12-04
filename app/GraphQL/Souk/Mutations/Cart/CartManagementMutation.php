<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Cart;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Users\Models\UserCompanyApps;

class CartManagementMutation
{
    public function add(mixed $root, array $request): array
    {
        $items = $request['items'];
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $cart = app('cart')->session($user->getId());
        $app = app(Apps::class);

        /**
         * @todo for now for b2b store clients
         * change this to use company group?
         */
        if ($app->get('USE_B2B_COMPANY_GROUP')) {
            if (UserCompanyApps::where('companies_id', $app->get('B2B_GLOBAL_COMPANY'))->where('apps_id', $app->getId())->first()) {
                $company = Companies::getById($app->get('B2B_GLOBAL_COMPANY'));
            }
        }

        //@todo send warehouse via header
        $useCompanySpecificPrice = $app->get(ConfigurationEnum::COMPANY_CUSTOM_CHANNEL_PRICING->value) ?? false;

        foreach ($items as $item) {
            $variant = Variants::getByIdFromCompany($item['variant_id'], $company);

            $cart->add([
                'id' => $variant->getId(),
                'name' => $variant->name,
                'price' => $useCompanySpecificPrice ? $variant->variantChannels('slug', $company->uuid)->firstOrFail()->price : $variant->variantWarehouses()->firstOrFail()->price, //@todo modify to use channel instead of warehouse
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
