<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Prices;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Prices\Actions\CreatePrice;
use Kanvas\Subscription\Prices\Actions\UpdatePrice;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price as PriceModel;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Stripe\Price as StripePrice;
use Stripe\Product as StripeProduct;

class PriceMutation
{
    /**
     * create.
     */
    public function create(array $req): PriceModel
    {
        $app = app(Apps::class);
        $stripeProduct = StripeProduct::create([
            'name' => 'Price for Plan ' . $req['input']['apps_plans_id'],
        ]);

        $stripePrice = StripePrice::create([
            'unit_amount' => $req['input']['amount'] * 100,
            'currency' => $req['input']['currency'],
            'recurring' => ['interval' => $req['input']['interval']],
            'product' => $stripeProduct->id,
        ]);

        $dto = PriceDto::viaRequest(
            array_merge($req['input'], ['stripe_id' => $stripePrice->id]),
            Auth::user(),
            $app
        );

        $action = new CreatePrice($dto, Auth::user());
        $priceModel = $action->execute();

        return $priceModel;
    }

    /**
     * update.
     */
    public function update(array $req): PriceModel
    {
        $app = app(Apps::class);
        $price = PriceRepository::getById($req['id']);

        StripePrice::create([
            'unit_amount' => $req['input']['amount'] * 100,
            'currency' => $price->currency,
            'recurring' => ['interval' => $price->interval],
            'product' => $price->stripe_id,
        ]);

        $dto = PriceDto::viaRequest($req['input'], Auth::user(), $app);
        $action = new UpdatePrice($price, $dto, Auth::user());
        $updatedPrice = $action->execute();

        return $updatedPrice;
    }

    /**
     * delete.
     */
    public function delete(array $req): bool
    {
        $price = PriceRepository::getById($req['id']);

        $price->delete();

        return true;
    }
}
