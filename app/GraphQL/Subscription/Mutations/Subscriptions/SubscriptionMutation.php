<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Subscriptions;

use Kanvas\Subscription\Subscriptions\Actions\CreateSubscription;
use Kanvas\Subscription\Subscriptions\Actions\UpdateSubscription;
use Kanvas\Subscription\Subscriptions\Actions\CancelSubscription;
use Kanvas\Subscription\Subscriptions\DataTransferObject\Subscription as SubscriptionDto;
use Kanvas\Subscription\Subscriptions\Models\Subscription as SubscriptionModel;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer as AppsStripeCustomerModel;
use Kanvas\Subscription\Subscriptions\Repositories\SubscriptionRepository;
use Kanvas\Apps\Models\Apps;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Stripe\Customer;
use Kanvas\Companies\Models\Companies;

class SubscriptionMutation
{
    public function __construct()
    {
        $app = app(Apps::class);
        Stripe::setApiKey($app->get('stripe_secret'));
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
        $app = app(Apps::class);
        $company = Companies::findOrFail($req['input']['companies_id']);
        $paymentMethodId = $req['input']['payment_method_id'];

        $appStripeCustomer = AppsStripeCustomerModel::where('companies_id', $company->id)
            ->where('apps_id', $app->id)
            ->first();

        if (! $appStripeCustomer) {
            $stripeCustomerId = $this->createStripeCustomer($company, $paymentMethodId);

            $appStripeCustomer = AppsStripeCustomerModel::create([
                'companies_id' => $company->id,
                'apps_id' => $app->id,
                'stripe_customer_id' => $stripeCustomerId,
            ]);
        }

        $stripeSubscription = StripeSubscription::create([
            'customer' => $appStripeCustomer->stripe_customer_id,
            'items' => array_map(function ($item) {
                return ['price' => $item['price_id']];
            }, $req['input']['items']),
            'default_payment_method' => $paymentMethodId,
            'trial_period_days' => $req['input']['trial_days'] ?? null,
        ]);

        $dto = SubscriptionDto::viaRequest($req['input'], Auth::user(), $company, $app);

        $action = new CreateSubscription($dto);
        $subscriptionModel = $action->execute();

        return $subscriptionModel;
    }

    public function update(array $req): SubscriptionModel
    {
        $app = app(Apps::class);
        $company = Companies::findOrFail($req['input']['companies_id']);

        $subscription = SubscriptionModel::findOrFail($req['input']['id']);

        if ($subscription->app_id != $app->id) {
            throw new \Exception("This subscription does not belong to the current app.");
        }

        $stripeSubscription = StripeSubscription::update($subscription->stripe_id, [
            'items' => array_map(function ($item) use ($subscription) {
                return [
                    'id' => $subscription->stripe_item_id,
                    'price' => $item['price_id'],
                ];
            }, $req['input']['items']),
        ]);
        ;

        $dto = SubscriptionDto::viaRequest($req['input'], Auth::user(), $company, $app);

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
        $app = app(Apps::class);
        $company = Companies::findOrFail($req['input']['companies_id']);
        $subscription = SubscriptionModel::findOrFail($req['id']);

        $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_id);
        $stripeSubscription->cancel();

        $dto = SubscriptionDto::viaRequest($req['input'], Auth::user(), $company, $app);

        $action = new CancelSubscription($subscription, $dto);
        $action->execute();

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

        return $customer->id;
    }
}
