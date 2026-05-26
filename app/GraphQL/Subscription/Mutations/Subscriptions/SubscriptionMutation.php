<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Subscriptions;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Kanvas\Subscription\Subscriptions\DataTransferObject\SubscriptionInput;
use Laravel\Cashier\Subscription;
use Throwable;

class SubscriptionMutation
{
    public function create(mixed $root, array $args): Subscription
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $data = $args['input'];
        $company = $user->getCurrentCompany();

        $subscriptionInput = SubscriptionInput::viaRequest($data, $user, $company, $app);

        $companyStripeAccount = $company->getStripeAccount($app);

        $existingSubscription = $companyStripeAccount->subscriptions->first();
        if ($existingSubscription !== null && $existingSubscription->valid()) {
            throw new ValidationException(
                'Company already has an active subscription. Use updateSubscription to change plan.'
            );
        }

        try {
            $subscription = $companyStripeAccount->newSubscription('default', $subscriptionInput->price->stripe_id);
            if ($subscriptionInput->price->plan->free_trial_dates) {
                $subscription->trialDays($subscriptionInput->price->plan->free_trial_dates);
            } else {
                $subscription->skipTrial();
            }

            $createdSubscription = $subscription->create($subscriptionInput->payment_method_id);

            foreach ($createdSubscription->items as $item) {
                $item->stripe_product_name = $subscriptionInput->price->plan->name;
                $item->save();
            }
        } catch (Throwable $e) {
            throw new ValidationException($e->getMessage());
        }

        return $createdSubscription;
    }

    public function update(mixed $root, array $args): Subscription
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $data = $args['input'];
        $company = $user->getCurrentCompany();
        $companyStripeAccount = $company->getStripeAccount($app);

        if (! $companyStripeAccount->subscriptions()->exists()) {
            throw new ValidationException('No active subscription found for your company');
        }
        $newPrice = PriceRepository::getByIdWithApp((int) $data['apps_plans_prices_id'], $app);

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
        $app = app(Apps::class);
        $user = auth()->user();
        $id = $args['id'];
        $company = $user->getCurrentCompany();
        $companyStripeAccount = $company->getStripeAccount($app);

        if (! $companyStripeAccount->subscriptions()->exists()) {
            throw new ValidationException('No active subscription found for your company');
        }

        $upgradeSubscription = $companyStripeAccount
            ->subscriptions()->where('id', $id)->first();

        if (! $upgradeSubscription) {
            throw new ValidationException('Trying to cancel a subscription that does not exist');
        }

        $cancelSubscription = $upgradeSubscription->cancel();

        return $cancelSubscription->ends_at !== null;
    }

    public function reactivate(mixed $root, array $args): bool
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $id = $args['id'];
        $company = $user->getCurrentCompany();
        $companyStripeAccount = $company->getStripeAccount($app);

        $subscription = $companyStripeAccount
            ->subscriptions()->where('id', $id)->first();

        if (! $subscription) {
            throw new ValidationException('Subscription not found');
        }

        $gracePeriodEnd = Carbon::parse($subscription->ends_at)->addDays(30);
        if (Carbon::now()->isAfter($gracePeriodEnd)) {
            $price = PriceRepository::getByStripeId($subscription->stripe_price, $app);

            try {
                $newSubscription = $companyStripeAccount->newSubscription('default', $price->stripe_id);
                if ($price->plan->free_trial_dates) {
                    $newSubscription->trialDays($price->plan->free_trial_dates);
                }

                $newSubscription->create($subscription->latestPayment()->payment_method);

                return true;
            } catch (Throwable $e) {
                throw new ValidationException('Failed to create new subscription: ' . $e->getMessage());
            }
        }

        try {
            $subscription->resume();

            return true;
        } catch (Throwable $e) {
            throw new ValidationException('Failed to reactivate subscription: ' . $e->getMessage());
        }
    }
}
