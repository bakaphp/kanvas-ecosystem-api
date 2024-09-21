<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Prices\Models\Price;
use Tests\TestCase;

final class SubscriptionsTest extends TestCase
{
    protected Companies $company;
    protected Apps $appModel;
    protected string $paymentMethodId;
    protected Plan $plan;
    protected $price;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = auth()->user()->getCurrentCompany();
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
        // Define the data you want to insert
        $prices = [
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
        ];

        foreach ($prices as $price) {
            DB::table('apps_plans_prices')->updateOrInsert(
                // Check if a record with the same `stripe_id` exists
                ['stripe_id' => $price['stripe_id']],
                // If it doesn't exist, insert the entire array
                $price
            );
        }
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

    public function testCreateSubscription()
    {
        $paymentMethod = $this->createPaymentMethod();
        $user = auth()->user();

        print_r($this->price->toArray());
echo '
            mutation {
                createSubscription(input: {
                    apps_plans_prices_id: ' . $this->price->getId() . ' , #Basic
                    name: "TestCreate Subscription",       
                    payment_method_id: "' . $paymentMethod . '",       
                }) {
                    id
                    stripe_id
                    stripe_status
                }
            }
        ';
        
        $response = $this->graphQL('
            mutation {
                createSubscription(input: {
                    apps_plans_prices_id: ' . $this->price->getId() . ' , #Basic
                    name: "TestCreate Subscription",       
                    payment_method_id: "' . $paymentMethod . '",       
                }) {
                    id
                    stripe_id
                    stripe_status
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ]);

        $response->assertJson([
            'data' => [
                'createSubscription' => [
                   'stripe_status' => 'active',
                ],
            ],
        ]);
    }

    public function testUpdateSubscription()
    {
        $user = auth()->user();
        $paymentMethod = $this->createPaymentMethod();

        $response = $this->graphQL('
        mutation {
            createSubscription(input: {
                apps_plans_prices_id: ' . $this->price->getId() . ' , #Basic
                name: "TestCreate Subscription",       
                payment_method_id: "' . $paymentMethod . '",       
            }) {
                id
                stripe_id
                stripe_status
            }
        }
    ', [], [], [
        'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
    ]);

        $response = $this->graphQL('
            mutation {
                updateSubscription(input: {
                    apps_plans_prices_id: ' . $this->price->getId() . ' , #Basic
                }) {
                    id
                    stripe_id
                    stripe_status
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ]);

        $response->assertJson([
            'data' => [
                'updateSubscription' => [
                    'stripe_status' => 'active',
                ],
            ],
        ]);
    }

    public function testCancelSubscription()
    {
        $user = auth()->user();
        $paymentMethod = $this->createPaymentMethod();

        $response = $this->graphQL('
        mutation {
            createSubscription(input: {
                apps_plans_prices_id: ' . $this->price->getId() . ' , #Basic
                name: "TestCreate Subscription",       
                payment_method_id: "' . $paymentMethod . '",       
            }) {
                id
                stripe_id
                stripe_status
            }
        }
    ', [], [], [
        'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
    ]);

        $id = $response->json('data.createSubscription.id');

        $subscription = $this->company->getStripeAccount($this->appModel)
            ->subscriptions()->where('type', $this->plan->stripe_plan)->first();

        $response = $this->graphQL('
            mutation {
                cancelSubscription(id: ' . $subscription->id . ')
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ]);

        $response->assertJson([
            'data' => [
                'cancelSubscription' => true,
            ],
        ]);
    }
}
