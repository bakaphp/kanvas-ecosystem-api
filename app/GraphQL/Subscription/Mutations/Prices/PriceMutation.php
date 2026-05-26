<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Prices;

use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;
use Kanvas\Subscription\Prices\Actions\CreatePriceAction;
use Kanvas\Subscription\Prices\Actions\UpdatePriceAction;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Kanvas\Subscription\Prices\Models\Price as PriceModel;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;

class PriceMutation
{
    public function create(mixed $root, array $req): PriceModel
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $data = $req['input'];
        $stripePlan = PlanRepository::getByIdWithApp((int) $data['apps_plans_id'], $app);
        $data['stripe_id'] = $stripePlan->stripe_id;

        $dto = PriceDto::viaRequest($data, $user, $app);

        return new CreatePriceAction($dto)->execute();
    }

    public function update(mixed $root, array $req): PriceModel
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $price = PriceRepository::getByIdWithApp((int) $req['id'], $app);

        // Overlay partial input on existing price values so single-field
        // updates (e.g. archive via {is_active: false}) don't null out
        // the immutable amount/currency/interval columns.
        $data = array_merge([
            'amount' => $price->amount,
            'currency' => $price->currency,
            'interval' => $price->interval,
            'is_active' => $price->is_active,
            'is_default' => $price->is_default,
        ], $req['input']);

        $data['stripe_id'] = $price->stripe_id;
        $dto = PriceDto::viaRequest($data, $user, $app);

        return new UpdatePriceAction($price, $dto)->execute();
    }
}
