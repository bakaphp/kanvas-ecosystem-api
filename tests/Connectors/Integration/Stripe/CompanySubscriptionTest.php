<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Subscription\Plans\Models\Plan;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

final class CompanySubscriptionTest extends TestCase
{
    protected Companies $company;
    protected Apps $appModel;
    protected string $paymentMethodId;
    protected Plan $plan;
    protected $price;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Companies::factory()->create();
        $this->appModel = app(Apps::class);
        if (empty($this->appModel->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            $this->appModel->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, getenv('TEST_STRIPE_SECRET_KEY'));
        }
        $this->paymentMethodId = $this->createPaymentMethod();
        $this->seedAppPlansPrices();
        $this->plan = Plan::where('apps_id', $this->appModel->getId())->firstOrFail();
        $this->price = $this->plan->price()->firstOrFail();
    }

    protected function seedAppPlansPrices()
    {
        DB::table('apps_plans_prices')->insert([
            [
                'apps_plans_id' => 1,
                'stripe_id' => 'price_1Q11XeBwyV21ueMMd6yZ4Tl5',
                'amount' => 59.00,
                'currency' => 'USD',
                'interval' => 'year',
                'is_default' => 1,
                'created_at' => now(),
            ],
            [
                'apps_plans_id' => 1,
                'stripe_id' => 'price_1Q1NGrBwyV21ueMMkJR2eA8U',
                'amount' => 5.00,
                'currency' => 'USD',
                'interval' => 'monthly',
                'is_default' => 0,
                'created_at' => now(),
            ],
        ]);
    }

    private function createPaymentMethod(): string
    {
        $cashier = $this->company->getStripeAccount($this->appModel)->stripe();
        $paymentMethod = $cashier->paymentMethods->create([
            'type' => 'card',
            'card' => [
                'number' => '4242424242424242',
                'exp_month' => 8,
                'exp_year' => date('Y') + 5,
                'cvc' => '314',
            ],
        ]);

        return $paymentMethod->id;
    }

    private function createSubscription(?int $trialDays = null): Subscription
    {
        $subscription = $this->company->getStripeAccount($this->appModel)
            ->newSubscription($this->plan->stripe_plan, $this->price->stripe_id);

        if ($trialDays) {
            $subscription->trialDays($trialDays);
        }

        return $subscription->create($this->paymentMethodId);
    }

    public function testCreateSubscription()
    {
        $startSubscription = $this->createSubscription();

        $this->assertInstanceOf(Subscription::class, $startSubscription);
        $this->assertEquals('active', $startSubscription->stripe_status);
        $this->assertEquals($this->company->id, $startSubscription->owner->company->id);
    }

    public function testCreateSubscriptionWithTrial()
    {
        $startSubscription = $this->createSubscription(14);

        $this->assertInstanceOf(Subscription::class, $startSubscription);
        $this->assertEquals('trialing', $startSubscription->stripe_status);
        $this->assertEquals($this->company->id, $startSubscription->owner->company->id);
    }

    public function testCancelSubscription()
    {
        $this->createSubscription();

        $cancelSubscription = $this->company->getStripeAccount($this->appModel)
            ->subscriptions()->where('type', $this->plan->stripe_plan)->first()
            ->cancel();

        $this->assertInstanceOf(Subscription::class, $cancelSubscription);
        $this->assertNotNull($cancelSubscription->ends_at);
        $this->assertEquals($this->company->id, $cancelSubscription->owner->company->id);
    }

    public function testUpgradeSubscription()
    {
        $this->createSubscription();

        $newPlan = Plan::fromApp($this->appModel)->where('id', '!=', $this->plan->id)->firstOrFail();
        $newPrice = $newPlan->price()->firstOrFail();

        $upgradeSubscription = $this->company->getStripeAccount($this->appModel)
            ->subscriptions()->where('type', $this->plan->stripe_plan)->first()
            ->swap($newPrice->stripe_id);

        $this->assertInstanceOf(Subscription::class, $upgradeSubscription);
        $this->assertEquals('active', $upgradeSubscription->stripe_status);
        $this->assertNotEquals($this->price->stripe_id, $upgradeSubscription->stripe_price);
    }

    public function testReactivateSubscription()
    {
        // Create and cancel a subscription
        $subscription = $this->createSubscription();
        $subscription->cancel();

        // Reactivate the subscription
        $reactivatedSubscription = $this->company->getStripeAccount($this->appModel)
            ->subscriptions()->where('type', $this->plan->stripe_plan)->first()
            ->resume();

        $this->assertInstanceOf(Subscription::class, $reactivatedSubscription);
        $this->assertEquals('active', $reactivatedSubscription->stripe_status);
        $this->assertNull($reactivatedSubscription->ends_at);
        $this->assertEquals($this->company->id, $reactivatedSubscription->owner->company->id);
    }
}
