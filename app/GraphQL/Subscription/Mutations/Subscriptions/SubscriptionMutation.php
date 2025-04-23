<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Subscriptions;

use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Subscription\Prices\Models\Price;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Kanvas\Subscription\Subscriptions\DataTransferObject\SubscriptionInput;
use Laravel\Cashier\Subscription;
use Throwable;

class SubscriptionMutation
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
    }

    public function create($root, array $args, $context): Subscription
    {
        $this->validateStripe();

        $data = $args['input'];
        $company = $this->user->getCurrentCompany();

        $subscriptionInput = SubscriptionInput::viaRequest(
            $data,
            $this->user,
            $company,
            $this->app
        );

        $companyStripeAccount = $company->getStripeAccount($this->app);

        if (!$companyStripeAccount->subscriptions()->exists()) {
            try {
                $subscription = $companyStripeAccount->newSubscription('default', $subscriptionInput->price->stripe_id);
                if ($subscriptionInput->price->plan->free_trial_dates) {
                    $subscription->trialDays($subscriptionInput->price->plan->free_trial_dates);
                }

                $createdSubscription = $subscription->create($subscriptionInput->payment_method_id);

                foreach ($createdSubscription->items as $item) {
                    $item->stripe_product_name = $subscriptionInput->price->plan->name;
                    $item->save();
                }
            } catch (Throwable $e) {
                throw new ValidationException($e->getMessage());
            }
        }

        return $companyStripeAccount->subscriptions()->firstOrFail();
    }

    public function update($root, array $args, $context, $info): Subscription
    {
        $this->validateStripe();

        $data = $args['input'];
        $company = $this->user->getCurrentCompany();
        $companyStripeAccount = $company->getStripeAccount($this->app);

        if (!$companyStripeAccount->subscriptions()->exists()) {
            throw new ValidationException('No active subscription found for your company');
        }
        $newPrice = PriceRepository::getByIdWithApp((int) $data['apps_plans_prices_id'], $this->app);

        $upgradeSubscription = $companyStripeAccount->subscriptions->first();

        if (isset($data['payment_method_id'])) {
            $companyStripeAccount->updateDefaultPaymentMethod($data['payment_method_id']);
        }

        $upgradeSubscription->swap($newPrice->stripe_id);

        foreach ($upgradeSubscription->items as $item) {
            $item->stripe_product_name = $newPrice->plan->name;
            $item->save();
        }

        return $upgradeSubscription;
    }

    public function cancel(mixed $root, array $args): bool
    {
        $this->validateStripe();

        $id = $args['id'];
        $company = $this->user->getCurrentCompany();
        $companyStripeAccount = $company->getStripeAccount($this->app);

        if (!$companyStripeAccount->subscriptions()->exists()) {
            throw new ValidationException('No active subscription found for your company');
        }

        $upgradeSubscription = $companyStripeAccount
            ->subscriptions()->where('id', $id)->first();

        if (!$upgradeSubscription) {
            throw new ValidationException('Trying to cancel a subscription that does not exist');
        }

        $cancelSubscription = $upgradeSubscription->cancel();

        return $cancelSubscription->ends_at !== null;
    }

    public function reactivate(mixed $root, array $args): Subscription
    {
        $this->validateStripe();

        $id = $args['id'];
        $company = $this->user->getCurrentCompany();
        $companyStripeAccount = $company->getStripeAccount($this->app);

        if (!$companyStripeAccount->subscriptions()->exists()) {
            throw new ValidationException('No subscriptions found for your company');
        }

        $subscription = $companyStripeAccount
            ->subscriptions()->where('id', $id)->first();

        if (!$subscription) {
            throw new ValidationException('Subscription not found');
        }

        // Check if the subscription is past the grace period (30 days)
        $gracePeriodEnd = Carbon::parse($subscription->ends_at)->addDays(30);
        if (Carbon::now()->isAfter($gracePeriodEnd)) {
            // Past grace period, create a new subscription
            $price = Price::where('stripe_id', $subscription->stripe_price)->firstOrFail();

            try {
                $newSubscription = $companyStripeAccount->newSubscription($price->plan->stripe_plan, $price->stripe_id);
                if ($price->plan->free_trial_days) {
                    $newSubscription->trialDays($price->plan->free_trial_days);
                }

                return $newSubscription->create($subscription->latestPayment()->payment_method);
            } catch (Throwable $e) {
                throw new ValidationException('Failed to create new subscription: '.$e->getMessage());
            }
        }

        // Within grace period, resume the existing subscription
        try {
            return $subscription->resume();
        } catch (Throwable $e) {
            throw new ValidationException('Failed to reactivate subscription: '.$e->getMessage());
        }
    }
}
