<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Mutations\Subscriptions;

use Kanvas\Subscription\Subscriptions\Actions\CreateSubscription;
use Kanvas\Subscription\Subscriptions\Actions\UpdateSubscription;
use Kanvas\Subscription\Subscriptions\Actions\CancelSubscription;
use Kanvas\Subscription\Subscriptions\Actions\AddSubscriptionItem;
use Kanvas\Subscription\Subscriptions\DataTransferObject\Subscription as SubscriptionDto;
use Kanvas\Subscription\SubscriptionItems\DataTransferObject\SubscriptionItem as SubscriptionItemDto;
use Kanvas\Subscription\Subscriptions\Models\Subscription as SubscriptionModel;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer as AppsStripeCustomerModel;
use App\GraphQL\Subscription\Mutations\SubscriptionItems\SubscriptionItemMutation;
use Kanvas\Apps\Models\Apps;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Stripe\Price;
use Stripe\Subscription as StripeSubscription;
use Stripe\Customer;
use Kanvas\Companies\Models\Companies;

class SubscriptionMutation
{
    private Apps $app;
    private $user;

    public function __construct()
    {
        $this->app = app(Apps::class);
        $this->user = auth()->user();
        Stripe::setApiKey($this->app->get('stripe_secret'));
    }

    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $args
     * @param  array $context
     *
     * @return SubscriptionModel
     */
    public function create($root, array $args, $context): SubscriptionModel
    {
        $data = $args['input'];
        $company = $this->user->getCurrentCompany();
        $paymentMethodId = $data['payment_method_id'];

        $appStripeCustomer = $this->storeAppCustomer($company, $paymentMethodId);

        $stripeSubscription = $this->createStripeSubscription($appStripeCustomer->stripe_customer_id, $data, $paymentMethodId);

        $dto = SubscriptionDto::viaRequest(array_merge($data, [
            'stripe_id' => $stripeSubscription->id,
            'payment_method_id' => $paymentMethodId,
        ]), $this->user, $company, $this->app);

        $subscriptionModel = (new CreateSubscription($dto))->execute();

        $this->storeSubscriptionItems($data['items'], $stripeSubscription, $subscriptionModel, $company);

        return $subscriptionModel;
    }

    public function update($root, array $args, $context, $info): SubscriptionModel
    {
        $data = $args['input'];
        $company = $this->user->getCurrentCompany();
        $subscription = SubscriptionModel::findOrFail($data['subscription_id']);

        if ($subscription->apps_id != $this->app->id) {
            throw new \Exception("This subscription does not belong to the current app.");
        }

        $stripeSubscription = StripeSubscription::update($subscription->stripe_id, [
            'metadata' => [
                'name' => $data['name'],
            ],
            'default_payment_method' => $data['payment_method_id'],
            'trial_end' => $data['trial_days'] ? strtotime("+{$data['trial_days']} days") : 'now',
        ]);

        $data['stripe_id'] = $subscription->stripe_id;

        $dto = SubscriptionDto::viaRequest($data, $this->user, $company, $this->app);
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
        $data = $req['input'];
        $company = Companies::findOrFail($data['companies_id']);
        $subscription = SubscriptionModel::findOrFail($req['id']);

        $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_id);
        $stripeSubscription->cancel();

        $dto = SubscriptionDto::viaRequest($data, $this->user, $company, $this->app);
        (new CancelSubscription($subscription, $dto))->execute();

        return $subscription;
    }
    /**
     * addSubscriptionItem.
     *
     * @param array $args
     * @return SubscriptionItem
     */
    public function addSubscriptionItem(array $args)
    {
        $data = $args['input'];
        $company = $this->user->getCurrentCompany();

        $subscriptionModel = SubscriptionModel::findOrFail($data['subscription_id']);

        $stripeSubscription = StripeSubscription::retrieve($subscriptionModel->stripe_id);
        $stripeSubscriptionItem = StripeSubscription::update($subscriptionModel->stripe_id, [
            'items' => [
                [
                    'price' => $data['price_id'],
                    'quantity' => $data['quantity'],
                ]
            ]
        ]);

        $subscriptionItemDto = SubscriptionItemDto::viaRequest(array_merge($data, [
            'stripe_id' => $stripeSubscriptionItem->id,
        ]), $this->user, $company, $this->app);

        $addSubscriptionItemAction = new AddSubscriptionItem($subscriptionItemDto);
        return $addSubscriptionItemAction->execute();
    }


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

    private function createStripeSubscription(string $stripeCustomerId, array $data, string $paymentMethodId)
    {
        return StripeSubscription::create([
            'customer' => $stripeCustomerId,
            'items' => array_map(function ($item) {
                return [
                    'price' => $item['price_id'],
                    'quantity' => $item['quantity'] ?? 1,
                ];
            }, $data['items']),
            'default_payment_method' => $paymentMethodId,
            'trial_end' => $data['trial_days'] ? strtotime("+{$data['trial_days']} days") : 'now',
        ]);
    }

    private function storeAppCustomer(Companies $company, string $paymentMethodId): AppsStripeCustomerModel
    {
        $existingCustomer = AppsStripeCustomerModel::where([
            'companies_id' => $company->id,
            'apps_id' => $this->app->id,
        ])->first();

        if ($existingCustomer) {
            return $existingCustomer;
        }

        $stripeCustomerId = $this->createStripeCustomer($company, $paymentMethodId);

        return AppsStripeCustomerModel::create([
            'companies_id' => $company->id,
            'apps_id' => $this->app->id,
            'stripe_customer_id' => $stripeCustomerId,
        ]);
    }

    private function storeSubscriptionItems(array $items, StripeSubscription $stripeSubscription, SubscriptionModel $subscriptionModel, Companies $company): void
    {
        $subscriptionItemMutation = new SubscriptionItemMutation();

        foreach ($items as $item) {
            $stripeSubscriptionItem = collect($stripeSubscription->items->data)
                ->firstWhere('price.id', $item['price_id']);

            $price = Price::retrieve($item['price_id']);
            $stripePlan = $price->product;

            $subscriptionItemDto = SubscriptionItemDto::viaRequest(array_merge($item, [
                'subscription_id' => $subscriptionModel->id,
                'stripe_id' => $stripeSubscriptionItem->id,
                'stripe_plan' => $stripePlan,
                'apps_plans_id' => $this->app->id, #MODIFY THIS
            ]), $this->user, $company, $this->app);

            $subscriptionItemMutation->create([
                'input' => $subscriptionItemDto->toArray()
            ]);
        }
    }
}
