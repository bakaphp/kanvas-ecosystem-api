<?php
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
    }
    public function execute(Apps $app, UserInterface $user, array $params): array
    {
        $this->validateStripe();
        $company = $this->user->getCurrentCompany();
        $response = [];
        $exception = null;

        try {
            $companyStripeAccount = $company->getStripeAccount($this->app);
            $plan = Plan::findOrFail($app->default_apps_plan_id);
            $price = $plan->prices()->where('is_default', 1)->firstOrFail();
            $trialEndsAt = now()->addDays($plan->free_trial_dates);

            $companyStripeAccount->newSubscription('default', $price->stripe_id)
            ->trialUntil($trialEndsAt);

            AppsStripeCustomer::create([
                'apps_id' => $app->id,
                'companies_id' => $company->id,
                'stripe_id' =>  $companyStripeAccount->id,
                'pm_type' => null,
                'pm_last_four' => null,
                'trial_ends_at' => $trialEndsAt,
                // 'is_deleted' => false,
                // 'created_at' => now(),
                // 'updated_at' => now(),
            ]);

            $response['status'] = 'success';
            $response['message'] = 'Plan assigned successfully. Trial period started.';
        } catch (Throwable $e) {
            $exception = $e;
            $response['status'] = 'error';
            $response['message'] = $e->getMessage();
        }

        return $response;
    }
}
