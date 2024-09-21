<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Subscription\Plans\Models\Plan;
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
        $this->plan = Plan::fromApp($this->appModel)->firstOrFail();
        $this->price = $this->plan->price()->firstOrFail();
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

        $response = $this->graphQL('
            mutation {
                createSubscription(input: {
                    apps_plans_prices_id: 1, #Basic
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
                apps_plans_prices_id: 1, #Basic
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
                    apps_plans_prices_id: 3 #Basic
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
                apps_plans_prices_id: 1, #Basic
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

        $response = $this->graphQL('
            mutation {
                cancelSubscription(id: ' . $id . ')
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
