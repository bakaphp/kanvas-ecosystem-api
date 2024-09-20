<?php

declare(strict_types=1);

namespace App\GraphQL\Subscriptions\Mutations\Plans;

use Kanvas\Subscription\Plans\Actions\CreatePlan;
use Kanvas\Subscription\Plans\Actions\UpdatePlan;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;
use Kanvas\Subscription\Plans\DataTransferObject\Plan as PlanDto;
use Kanvas\Subscription\Plans\Models\Plan as PlanModel;
use Kanvas\Apps\Models\Apps;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Stripe\Product as StripeProduct;

class PlanMutation
{
    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return PlanModel
     */
    public function create(mixed $root, array $req): PlanModel
    {
        $app = app(Apps::class);
        $stripeProduct = StripeProduct::create([
            'name' => $req['input']['name'],
            'description' => $req['input']['description'] ?? '',
        ]);

        $dto = PlanDto::viaRequest(
            array_merge($req['input'], ['stripe_id' => $stripeProduct->id]),
            Auth::user(),
            $app
        );

        $action = new CreatePlan($dto);

        return $action->execute();
    }

    /**
     * update.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return PlanModel
     */
    public function update(array $req): PlanModel
    {
        $app = app(Apps::class);
        $plan = PlanRepository::getById($req['id']);

        StripeProduct::update($plan->stripe_id, [
            'name' => $req['input']['name'] ?? $plan->name,
            'description' => $req['input']['description'] ?? $plan->description,
        ]);

        $dto = PlanDto::viaRequest($req['input'], Auth::user(), $app);

        $action = new UpdatePlan($plan, $dto);

        return $action->execute();
    }

    /**
     * delete.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function delete(array $req): bool
    {
        $plan = PlanRepository::getById($req['id']);

        $stripeProduct = StripeProduct::retrieve($plan->stripe_id);
        $stripeProduct->delete();

        $plan->delete();

        return true;
    }
}
