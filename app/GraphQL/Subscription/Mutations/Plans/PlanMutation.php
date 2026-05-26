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
        $dto = PlanDto::viaRequest($data, $user, $app);
        $newPlan = new CreatePlanAction($dto, $user)->execute();

        if (! empty($data['prices'])) {
            foreach ($data['prices'] as $priceData) {
                $priceData['apps_plans_id'] = (string) $newPlan->id;
                $priceData['stripe_id'] = $newPlan->stripe_id;

                $priceDto = PriceDto::viaRequest($priceData, $user, $app);
                new CreatePriceAction($priceDto)->execute();
            }
        }

        return $newPlan;
    }

    public function update(mixed $root, array $req): PlanModel
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $plan = PlanRepository::getByIdWithApp((int) $req['id']);

        // Overlay partial input on existing plan values so archive flows
        // (e.g. {is_active: false} only) don't null out other columns.
        $data = array_merge([
            'name' => $plan->name,
            'description' => $plan->description,
            'free_trial_dates' => $plan->free_trial_dates,
            'is_active' => $plan->is_active,
            'is_default' => $plan->is_default,
        ], $req['input']);

        StripeProduct::update($plan->stripe_id, [
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'active' => (bool) $data['is_active'],
        ]);

        $data['stripe_id'] = $plan->stripe_id;
        $dto = PlanDto::viaRequest($data, $user, $app);

        return new UpdatePlanAction($plan, $dto)->execute();
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
