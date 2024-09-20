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
use Kanvas\Subscription\Subscriptions\Repositories\SubscriptionRepository;
use Kanvas\Subscription\Plans\Repositories\PlanRepository;
use App\GraphQL\Subscription\Mutations\SubscriptionItems\SubscriptionItemMutation;
use Kanvas\Apps\Models\Apps;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Stripe\Price;
use Stripe\Subscription as StripeSubscription;
use Stripe\Customer;
use Kanvas\Companies\Models\Companies;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;

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

        $appStripeCustomer = $this->createOrFindAppCustomer($company, $paymentMethodId);
        $stripeSubscription = $this->createStripeSubscription($appStripeCustomer->stripe_customer_id, $data, $paymentMethodId);

        $dto = SubscriptionDto::viaRequest(array_merge($data, [
            'stripe_id' => $stripeSubscription->id,
            'payment_method_id' => $paymentMethodId,
        ]), $this->user, $company, $this->app);

        $action = new CreateSubscription($dto);
        $subscriptionModel = $action->execute();

        foreach ($data['items'] as $item) {
            $price = PriceRepository::getByIdWithApp($item['apps_plans_prices_id']);
            $plan = PlanRepository::getByIdWithApp($price->apps_plans_id);
            $stripeSubscriptionItem = collect($stripeSubscription->items->data)
                ->firstWhere('price.id', $price-> stripe_id);

            $subscriptionItemDto = SubscriptionItemDto::viaRequest(array_merge($item, [
                'subscription_id' => $subscriptionModel->id,
                'stripe_id' => $stripeSubscriptionItem->id,
                'apps_plans_id' => $plan->id,
            ]), $this->user, $company, $this->app);

            $action = new AddSubscriptionItem($subscriptionItemDto);
            $subscriptionItemModel = $action->execute();
        }

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

        StripeSubscription::update($subscription->stripe_id, [
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
    public function cancel(mixed $root, array $req): bool
    {
        $subscriptionId = (int) $req['id'];
        $subscription = SubscriptionRepository::getById($subscriptionId, auth()->user()->getCurrentCompany());

        $stripeSubscription = StripeSubscription::retrieve($subscription->stripe_id);
        $stripeSubscription->cancel();

        (new CancelSubscription($subscription))->execute();
        return $subscription->delete();
    }

    /**
     * addSubscriptionItem.
     *
     * @param array $args
     * @return SubscriptionItems
     */
    public function addSubscriptionItem($rootValue, array $args, $context)
    {
        $data = $args['input'];
        $company = $this->user->getCurrentCompany();
        $subscriptionId = (int) $data['subscription_id'];
        $subscriptionModel = SubscriptionModel::findOrFail($subscriptionId);
        $stripeSubscription = StripeSubscription::retrieve($subscriptionModel->stripe_id);

        $subscriptionItems = [];
    
        foreach ($data['items'] as $item) {
            $price = PriceRepository::getByIdWithApp($item['apps_plans_prices_id']);
            $plan = PlanRepository::getByIdWithApp($price->apps_plans_id);
            $existingSubscriptionItem = collect($stripeSubscription->items->data)
                ->firstWhere('price.id', $price->stripe_id);
    
            $stripeItemData = [
                'price' => $price->stripe_id,
                'quantity' => $item['quantity'],
            ];
    
            if ($existingSubscriptionItem) {
                $stripeItemData['id'] = $existingSubscriptionItem->id;
            }
    
            StripeSubscription::update($subscriptionModel->stripe_id, [
                'items' => [$stripeItemData]
            ]);
    
            $stripeSubscriptionItem = collect($stripeSubscription->items->data)
                ->firstWhere('price.id', $price->stripe_id);
    
            $subscriptionItemDto = SubscriptionItemDto::viaRequest(array_merge($item, [
                'subscription_id' => $subscriptionModel->id,
                'stripe_id' => $stripeSubscriptionItem->id,
                'apps_plans_id' => $plan->id,
            ]), $this->user, $company, $this->app);
    
            $action = new AddSubscriptionItem($subscriptionItemDto);
            $subscriptionItemModel = $action->execute();
    
            $subscriptionItems[] = $subscriptionItemModel;
        }
    
        return $subscriptionItems;
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
            $price = PriceRepository::getByIdWithApp($item['apps_plans_prices_id']);
            return [
                'price' => $price->stripe_id,
                'quantity' => $item['quantity'] ?? 1,
            ];
            }, $data['items']),
            'default_payment_method' => $paymentMethodId,
            'trial_end' => $data['trial_days'] ? strtotime("+{$data['trial_days']} days") : 'now',
        ]);
    }

    private function createOrFindAppCustomer(Companies $company, string $paymentMethodId): AppsStripeCustomerModel
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
}