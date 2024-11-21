<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Workflows\Activities;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Workflow\KanvasActivities;
use Throwable;

class SetPlanWithoutPaymentActivity extends KanvasActivities
{
    public $tries = 5;

    /**
     * @todo move to middleware
     */
    public function validateStripe(Apps $app)
    {
        if (empty($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            throw new ValidationException('Stripe is not configured for this app');
        }
    }

    public function execute(UserInterface $user, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $this->validateStripe($app);
        $company = $user->getCurrentCompany();
        $response = [];
        $companyStripeAccount = $company->getStripeAccount($app);

        if (! $companyStripeAccount->subscriptions()->exists()) {
            try {
                $plan = Plan::findOrFail($app->default_apps_plan_id);
                $price = $plan->price()->where('is_default', 1)->firstOrFail();
                $trialEndsAt = now()->addDays($plan->free_trial_dates);

                $subscription = $companyStripeAccount->newSubscription('default', $price->stripe_id)
                    ->trialUntil($trialEndsAt)
                    ->create();

                foreach ($subscription->items as $item) {
                    $item->stripe_product_name = $plan->name;
                    $item->save();
                }

                $response = [
                    'status' => 'success',
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                    'default_plan' => $plan->name,
                    'trial_ends_at' => $trialEndsAt->toDateTimeString(),
                ];
            } catch (Throwable $e) {
                $response = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                    'default_plan' => $app->default_apps_plan_id,
                ];
            }
        }

        return $response;
    }
}
