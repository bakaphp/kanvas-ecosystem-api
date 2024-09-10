<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Subscriptions;

use Kanvas\Subscription\Subscriptions\Actions\CreateSubscription;
use Kanvas\Subscription\Subscriptions\Actions\UpdateSubscription;
use Kanvas\Subscription\Subscriptions\DataTransferObject\Subscription as SubscriptionDto;
use Kanvas\Subscription\Subscriptions\Models\Subscription as SubscriptionModel;
use Kanvas\Subscription\Subscriptions\Repositories\SubscriptionRepository;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\Customer;
use Kanvas\Companies\Models\Companies;
use Stripe\PaymentMethod;

class SubscriptionMutation
{
    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return SubscriptionModel
     */
    public function create(mixed $root, array $req): SubscriptionModel
    {
        Stripe::setApiKey(env('STRIPE_SECRET'));

        $company = Companies::findOrFail($req['input']['companies_id']);
        
        $paymentMethodId = $req['input']['payment_method_id'];

        $customer_id = $company->stripe_id ?? $this->createStripeCustomer($company, $paymentMethodId);

        $stripeSubscription = StripeSubscription::create([
            'customer' => $customer_id,
            'items' => [
                ['price' => $req['input']['price_id']],
            ],
            'default_payment_method' => $paymentMethodId,
        ]);

        $dto = SubscriptionDto::viaRequest($req['input'], Auth::user(), $stripeSubscription);

        $action = new CreateSubscription($dto);
        $subscriptionModel = $action->execute();

        return $subscriptionModel;
    }

    public function update(mixed $root, array $req): SubscriptionModel
    {
        new StripeClient(env('STRIPE_SECRET'));

        $subscription = SubscriptionModel::findOrFail($req['id']);
        $stripeSubscription = StripeSubscription::update($subscription->stripe_id, [
            'items' => [
                ['id' => $subscription->stripe_item_id, 'price' => $req['input']['price_id']],
            ],
        ]);

        $dto = SubscriptionDto::viaRequest($req['input'], Auth::user(), $stripeSubscription);

        $action = new UpdateSubscription($subscription, $dto);
        $subscriptionModel = $action->execute();

        return $subscriptionModel;
    }

    /**
     * cancel.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return SubscriptionModel
     */
    public function cancel(mixed $root, array $req): SubscriptionModel
    {
        $subscription = SubscriptionRepository::cancel($req['id']);
        return $subscription;
    }

    /**
     * Create a new Stripe customer.
     *
     * @param Companies $company
     * @param string $paymentMethodId
     * @return string
     */
    private function createStripeCustomer(Companies $company, string $paymentMethodId): string
    {
        $customer = Customer::create([
            'email' => $company->email,
            'name' => $company->name,
            'payment_method' => $paymentMethodId,
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId,
            ],
        ]);

        $company->update(['stripe_id' => $customer->id]);

        return $customer->id;
    }
}