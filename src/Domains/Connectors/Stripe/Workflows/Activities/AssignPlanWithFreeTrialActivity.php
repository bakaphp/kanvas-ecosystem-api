<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Laravel\Cashier\Subscription;
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
        $exception = null;

        try {
            $companyStripeAccount = $company->getStripeAccount($app);
            //print("companyStripeAccount " . $companyStripeAccount . "\n");
            $plan = Plan::findOrFail($app->default_apps_plan_id);
           // print("plan " . $plan . "\n");
            $price = $plan->prices()->where('is_default', 1)->firstOrFail();
            print("price " . $price . "\n");
            $trialEndsAt = now()->addDays($plan->free_trial_dates);
            print("trialEndsAt" . $trialEndsAt. "\n");

            $companyStripeAccount->newSubscription('default', $price->stripe_id)
            ->trialUntil($trialEndsAt);
            print("companyStripeAccountAfterStripe " . $companyStripeAccount . "\n");

            AppsStripeCustomer::create([
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'stripe_id' =>  $companyStripeAccount->id,
                'trial_ends_at' => $trialEndsAt,
            ]);

            $response['status'] = 'success';
            $response['message'] = 'Plan assigned successfully. Trial period started.';
        } catch (Throwable $e) {
            $exception = $e;
            $response['status'] = 'error';
            $response['message'] = $exception->getMessage();
        }

        return $response;
    }
}
