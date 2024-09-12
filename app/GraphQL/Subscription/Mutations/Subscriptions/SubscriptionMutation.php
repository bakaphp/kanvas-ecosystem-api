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
use Stripe\Subscription as StripeSubscription;
use Stripe\Customer;
use Kanvas\Companies\Models\Companies;

class SubscriptionMutation
{   
    public function __construct()
    {
        Stripe::setApiKey(env('STRIPE_SECRET'));
    }

    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return SubscriptionModel
     */
    public function create(array $req): SubscriptionModel
    {

        $company = Companies::findOrFail($req['input']['companies_id']);
        
        $paymentMethodId = $req['input']['payment_method_id'];

        $customer_id = $company->stripe_id ?? $this->createStripeCustomer($company, $paymentMethodId);

        $stripeSubscription = StripeSubscription::create([
            'customer' => $customer_id,
            'items' => array_map(function ($item) {
                return ['price' => $item['price_id']];
            }, $req['input']['items']),
            'default_payment_method' => $paymentMethodId,
            'trial_period_days' => $req['input']['trial_days'] ?? null, // Manejo de trial_days opcional
        ]);

        $dto = SubscriptionDto::viaRequest($req['input'], Auth::user(), $stripeSubscription);

        $action = new CreateSubscription($dto);
        $subscriptionModel = $action->execute();

        return $subscriptionModel;
    }

    public function update(array $req): SubscriptionModel
    {

        $subscription = SubscriptionModel::findOrFail($req['input']['id']);

        $stripeSubscription = StripeSubscription::update($subscription->stripe_id, [
            'items' => array_map(function ($item) use ($subscription) {
                return [
                    'id' => $subscription->stripe_item_id, // Id del item de suscripción existente
                    'price' => $item['price_id'], // Nuevo price_id
                ];
            }, $req['input']['items']),
        ]);;

        $dto = SubscriptionDto::viaRequest($req['input'], Auth::user(), $stripeSubscription);

        (new UpdateSubscription($subscription, $dto))->execute();
        
        return $subscription;
    }

    /**
     * cancel.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return SubscriptionModel
     */
    public function cancel(array $req): SubscriptionModel
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