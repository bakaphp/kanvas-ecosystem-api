<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Plans;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Plans\Actions\CreatePlan;
use Kanvas\Subscription\Plans\Actions\UpdatePlan;
use Kanvas\Subscription\Plans\DataTransferObject\Plan as PlanDto;
use Kanvas\Subscription\Plans\Models\Plan as PlanModel;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;
use App\GraphQL\Subscription\Mutations\Prices\PriceMutation;
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
        $action = new CreatePlan($dto, $this->user);
        $newPlan = $action->execute();

        if (! empty($data['prices'])) {
            $priceMutation = new PriceMutation();
            foreach ($data['prices'] as $priceData) {
                $priceData['apps_plans_id'] = $newPlan->id;
                $priceMutation->create(['input' => $priceData]);
            }
        }

        return $newPlan;
    }

    /**
     * update.
     *
     * @param  mixed $root
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
