<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Throwable;
use Workflow\Activity;

class AssignPlanWithFreeTrialActivity extends Activity
{
    public $tries = 5;
    /**
     * @todo move to middleware
     */
    public function validateStripe()
    {
        $app = app(Apps::class);

        if (empty($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            throw new ValidationException('Stripe is not configured for this app');
        }
    }
    public function execute(UserInterface $user, Apps $app, array $params): array
    {
        $this->validateStripe();
        $company = $user->getCurrentCompany();
        $response = [];
        $companyStripeAccount = $company->getStripeAccount($app);

        if (!$companyStripeAccount->subscriptions()->exists()){
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

                $response['status'] = 'success';
            } catch (Throwable $e) {
                $exception = $e;
                $response['status'] = 'error';
                $response['message'] = $exception->getMessage();
            }
        }
        return $response;
    }
}
