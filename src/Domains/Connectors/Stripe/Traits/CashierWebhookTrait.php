<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Traits;

use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Payment;
use Laravel\Cashier\Subscription;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

/**
 * this is a copy and paste from laravel cashier webhook controller
 * Laravel\Cashier\Http\Controllers;
 * we need to look for a better way to keep this update without copy and paste
 */
trait CashierWebhookTrait
{
    /**
    * Handle customer subscription created.
    */
    protected function handleCustomerSubscriptionCreated(array $payload)
    {
        $user = $this->getUserByStripeId($payload['data']['object']['customer']);

        if ($user) {
            $data = $payload['data']['object'];

            if (! $user->subscriptions->contains('stripe_id', $data['id'])) {
                if (isset($data['trial_end'])) {
                    $trialEndsAt = Carbon::createFromTimestamp($data['trial_end']);
                } else {
                    $trialEndsAt = null;
                }

                $firstItem = $data['items']['data'][0];
                $isSinglePrice = count($data['items']['data']) === 1;

                $subscription = $user->subscriptions()->create([
                    'type' => $data['metadata']['type'] ?? $data['metadata']['name'] ?? $this->newSubscriptionType($payload),
                    'stripe_id' => $data['id'],
                    'stripe_status' => $data['status'],
                    'stripe_price' => $isSinglePrice ? $firstItem['price']['id'] : null,
                    'quantity' => $isSinglePrice && isset($firstItem['quantity']) ? $firstItem['quantity'] : null,
                    'trial_ends_at' => $trialEndsAt,
                    'ends_at' => null,
                ]);

                foreach ($data['items']['data'] as $item) {
                    $subscription->items()->create([
                        'stripe_id' => $item['id'],
                        'stripe_product' => $item['price']['product'],
                        'stripe_price' => $item['price']['id'],
                        'quantity' => $item['quantity'] ?? null,
                    ]);
                }
            }

            // Terminate the billable's generic trial if it exists...
            if (! is_null($user->trial_ends_at)) {
                $user->trial_ends_at = null;
                $user->save();
            }
        }

        return $this->successMethod();
    }

    /**
     * Determines the type that should be used when new subscriptions are created from the Stripe dashboard.
     *
     * @return string
     */
    protected function newSubscriptionType(array $payload)
    {
        return 'default';
    }

    /**
     * Handle customer subscription updated.
     */
    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        if ($user = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            $data = $payload['data']['object'];

            $subscription = $user->subscriptions()->firstOrNew(['stripe_id' => $data['id']]);

            if (
                isset($data['status']) &&
                $data['status'] === StripeSubscription::STATUS_INCOMPLETE_EXPIRED
            ) {
                $subscription->items()->delete();
                $subscription->delete();

                return;
            }

            $subscription->type = $subscription->type ?? $data['metadata']['type'] ?? $data['metadata']['name'] ?? $this->newSubscriptionType($payload);

            $firstItem = $data['items']['data'][0];
            $isSinglePrice = count($data['items']['data']) === 1;

            // Price...
            $subscription->stripe_price = $isSinglePrice ? $firstItem['price']['id'] : null;

            // Quantity...
            $subscription->quantity = $isSinglePrice && isset($firstItem['quantity']) ? $firstItem['quantity'] : null;

            // Trial ending date...
            if (isset($data['trial_end'])) {
                $trialEnd = Carbon::createFromTimestamp($data['trial_end']);

                if (! $subscription->trial_ends_at || $subscription->trial_ends_at->ne($trialEnd)) {
                    $subscription->trial_ends_at = $trialEnd;
                }
            }

            // Cancellation date...
            if ($data['cancel_at_period_end'] ?? false) {
                $subscription->ends_at = $subscription->onTrial()
                    ? $subscription->trial_ends_at
                    : Carbon::createFromTimestamp($data['current_period_end']);
            } elseif (isset($data['cancel_at']) || isset($data['canceled_at'])) {
                $subscription->ends_at = Carbon::createFromTimestamp($data['cancel_at'] ?? $data['canceled_at']);
            } else {
                $subscription->ends_at = null;
            }

            // Status...
            if (isset($data['status'])) {
                $subscription->stripe_status = $data['status'];
            }

            $subscription->save();

            // Update subscription items...
            if (isset($data['items'])) {
                $subscriptionItemIds = [];

                foreach ($data['items']['data'] as $item) {
                    $subscriptionItemIds[] = $item['id'];

                    $subscription->items()->updateOrCreate([
                        'stripe_id' => $item['id'],
                    ], [
                        'stripe_product' => $item['price']['product'],
                        'stripe_price' => $item['price']['id'],
                        'quantity' => $item['quantity'] ?? null,
                    ]);
                }

                // Delete items that aren't attached to the subscription anymore...
                $subscription->items()->whereNotIn('stripe_id', $subscriptionItemIds)->delete();
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle the cancellation of a customer subscription.
     */
    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        if ($user = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            $user->subscriptions->filter(function ($subscription) use ($payload) {
                return $subscription->stripe_id === $payload['data']['object']['id'];
            })->each(function ($subscription) {
                $subscription->skipTrial()->markAsCanceled();
            });
        }

        return $this->successMethod();
    }

    /**
     * Handle customer updated.
     */
    protected function handleCustomerUpdated(array $payload)
    {
        if ($user = $this->getUserByStripeId($payload['data']['object']['id'])) {
            $user->updateDefaultPaymentMethodFromStripe();
        }

        return $this->successMethod();
    }

    /**
     * Handle deleted customer.
     */
    protected function handleCustomerDeleted(array $payload)
    {
        if ($user = $this->getUserByStripeId($payload['data']['object']['id'])) {
            $user->subscriptions->each(function (Subscription $subscription) {
                $subscription->skipTrial()->markAsCanceled();
            });

            $user->forceFill([
                'stripe_id' => null,
                'trial_ends_at' => null,
                'pm_type' => null,
                'pm_last_four' => null,
            ])->save();
        }

        return $this->successMethod();
    }

    /**
     * Handle payment method automatically updated by vendor.
     */
    protected function handlePaymentMethodAutomaticallyUpdated(array $payload)
    {
        if ($user = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            $user->updateDefaultPaymentMethodFromStripe();
        }

        return $this->successMethod();
    }

    /**
     * Handle payment action required for invoice.
     */
    protected function handleInvoicePaymentActionRequired(array $payload)
    {
        if (is_null($notification = config('cashier.payment_notification'))) {
            return $this->successMethod();
        }

        if ($payload['data']['object']['metadata']['is_on_session_checkout'] ?? false) {
            return $this->successMethod();
        }

        if ($payload['data']['object']['subscription_details']['metadata']['is_on_session_checkout'] ?? false) {
            return $this->successMethod();
        }

        if ($user = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            if (in_array(Notifiable::class, class_uses_recursive($user))) {
                $payment = new Payment($user->stripe()->paymentIntents->retrieve(
                    $payload['data']['object']['payment_intent']
                ));

                $user->notify(new $notification($payment));
            }
        }

        return $this->successMethod();
    }

    /**
     * Get the customer instance by Stripe ID.
     *
     * @param  string|null  $stripeId
     * @return \Laravel\Cashier\Billable|null
     */
    protected function getUserByStripeId($stripeId)
    {
        /**
         * this is the only place we actually use the custom customer model
         */
        Cashier::useCustomerModel(AppsStripeCustomer::class);

        return Cashier::findBillable($stripeId);
    }

    protected function successMethod($parameters = [])
    {
        return 'Webhook Handled';
    }

    protected function missingMethod($parameters = [])
    {
        return 'Webhook Not Handled';
    }

    /**
     * Set the number of automatic retries due to an object lock timeout from Stripe.
     *
     * @param  int  $retries
     * @return void
     */
    protected function setMaxNetworkRetries($retries = 3)
    {
        Stripe::setMaxNetworkRetries($retries);
    }
}
