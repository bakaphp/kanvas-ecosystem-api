<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Plans;

use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Plans\Actions\CreatePlanAction;
use Kanvas\Subscription\Plans\Actions\UpdatePlanAction;
use Kanvas\Subscription\Plans\DataTransferObject\Plan as PlanDto;
use Kanvas\Subscription\Plans\Models\Plan as PlanModel;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;
use Kanvas\Subscription\Prices\Actions\CreatePriceAction;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Stripe\Product as StripeProduct;

class PlanMutation
{
    public function create(mixed $root, array $req): PlanModel
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $data = $req['input'];

        $stripeProduct = StripeProduct::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
        ]);

        $data['stripe_id'] = $stripeProduct->id;
        $newPlan = new CreatePlanAction(
            PlanDto::from($app, $user, $data)
        )->execute();

        if (! empty($data['prices'])) {
            foreach ($data['prices'] as $priceData) {
                $priceData['apps_plans_id'] = (string) $newPlan->id;
                $priceData['stripe_id'] = $newPlan->stripe_id;

                new CreatePriceAction(
                    PriceDto::from($app, $user, $priceData)
                )->execute();
            }
        }

        return $newPlan;
    }

    public function update(mixed $root, array $req): PlanModel
    {
        $app = app(Apps::class);
        $user = auth()->user();
        /** @var PlanModel $plan */
        $plan = PlanRepository::getByIdWithApp((int) $req['id']);
        $input = $req['input'];

        StripeProduct::update($plan->stripe_id, [
            'name' => $input['name'] ?? $plan->name,
            'description' => $input['description'] ?? $plan->description,
            'active' => (bool) ($input['is_active'] ?? $plan->is_active),
        ]);

        return new UpdatePlanAction(
            $plan,
            PlanDto::forUpdate(
                $plan,
                $app,
                $user,
                $input
            ),
        )->execute();
    }

    public function delete(mixed $root, array $req): bool
    {
        $plan = PlanRepository::getByIdWithApp((int) $req['id']);

        $stripeProduct = StripeProduct::retrieve($plan->stripe_id);
        $stripeProduct->delete();

        $plan->delete();

        return true;
    }
}
