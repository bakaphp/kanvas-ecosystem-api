<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Plans;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Subscription\Plans\Actions\CreatePlanAction;
use Kanvas\Subscription\Plans\Actions\UpdatePlanAction;
use Kanvas\Subscription\Plans\DataTransferObject\Plan as PlanDto;
use Kanvas\Subscription\Plans\Models\Plan as PlanModel;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;
use Kanvas\Subscription\Prices\Actions\CreatePriceAction;
use Kanvas\Subscription\Prices\DataTransferObject\Price as PriceDto;
use Stripe\Product as StripeProduct;
use Stripe\Stripe;

class PlanMutation
{
    private ?Apps $app = null;
    private ?UserInterface $user = null;

    /**
     * @todo move to middleware
     */
    public function validateStripe()
    {
        $this->app = app(Apps::class);
        $this->user = auth()->user();

        if (empty($this->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            throw new ValidationException('Stripe is not configured for this app');
        }
        Stripe::setApiKey($this->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));
    }

    /**
     * create.
     */
    public function create(mixed $root, array $req): PlanModel
    {
        $this->validateStripe();
        $data = $req['input'];

        $stripeProduct = StripeProduct::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
        ]);

        $data['stripe_id'] = $stripeProduct->id;
        $dto = PlanDto::viaRequest($data, $this->user, $this->app);
        $action = new CreatePlanAction($dto, $this->user);
        $newPlan = $action->execute();

        if (! empty($data['prices'])) {
            foreach ($data['prices'] as $priceData) {
                $priceData['apps_plans_id'] = (string)$newPlan->id;
                $priceData['stripe_id'] = $newPlan->stripe_id;

                $priceDto = PriceDto::viaRequest($priceData, $this->user, $this->app);
                $action = new CreatePriceAction($priceDto);
                $action->execute();
            }
        }

        return $newPlan;
    }

    /**
     * update.
     */
    public function update(mixed $root, array $req): PlanModel
    {
        $this->validateStripe();
        $data = $req['input'];
        $plan = PlanRepository::getByIdWithApp((int)$req['id']);

        StripeProduct::update($plan->stripe_id, [
            'name' => $data['name'] ?? $plan->name,
            'description' => $data['description'] ?? $plan->description,
            'active' => $data['is_active'],
        ]);

        $data['stripe_id'] = $plan->stripe_id;
        $dto = PlanDto::viaRequest($data, $this->user, $this->app);
        $action = new UpdatePlanAction($plan, $dto);

        return $action->execute();
    }

    /**
     * delete.
     */
    public function delete(mixed $root, array $req): bool
    {
        $this->validateStripe();
        $plan = PlanRepository::getByIdWithApp((int)$req['id']);

        $stripeProduct = StripeProduct::retrieve($plan->stripe_id);
        $stripeProduct->delete();

        $plan->delete();

        return true;
    }
}
