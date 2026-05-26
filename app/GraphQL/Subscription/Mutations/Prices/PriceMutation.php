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

        return new CreatePriceAction(PriceDto::from($app, $user, $data))->execute();
    }

    public function update(mixed $root, array $req): PriceModel
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $price = PriceRepository::getByIdWithApp((int) $req['id'], $app);

        return new UpdatePriceAction(
            $price,
            PriceDto::forUpdate(
                $price,
                $app,
                $user,
                $req['input'],
            ),
        )->execute();
    }
}
