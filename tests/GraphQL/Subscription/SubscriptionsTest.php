<?php
declare(strict_types=1);

namespace Tests\GraphQL\Subscriptions;

use Kanvas\Subscriptions\Models\Subscription;
use Tests\TestCase;

class SubscriptionsTest extends TestCase
{
    public function testCreateSubscription()
    {
        $input = [
            'users_id' => 1,
            'user_id' => 1,
            'companies_id' => 1,
            'apps_id' => 1,
            'apps_plans_id' => 1,
            'name' => 'Test Subscription',
            'stripe_id' => 'stripe_id',
            'stripe_plan' => 'stripe_plan',
            'stripe_status' => 'active',
            'quantity' => 1,
            'trial_ends_at' => null,
            'grace_period_ends' => null,
            'next_due_payment' => null,
            'ends_at' => null,
            'payment_frequency_id' => 1,
            'trial_ends_days' => 14,
            'is_freetrial' => true,
            'is_active' => true,
            'is_cancelled' => false,
            'paid' => false,
            'charge_date' => null,
        ];

        $response = $this->graphQL('
            mutation($input: SubscriptionInput!) {
                createSubscription(input: $input) {
                    id
                    name
                }
            }
        ', [
            'input' => $input,
        ]);

        $response->assertJson([
            'data' => [
                'createSubscription' => [
                    'name' => 'Test Subscription',
                ],
            ],
        ]);
    }

    public function testChangePlan()
    {
        $subscription = Subscription::create([
            'users_id' => 1,
            'user_id' => 1,
            'companies_id' => 1,
            'apps_id' => 1,
            'apps_plans_id' => 1,
            'name' => 'Test Subscription',
        ]);

        $response = $this->graphQL('
            mutation {
                changePlan(subscriptionId: ' . $subscription->id . ', newPlanId: 2) {
                    id
                    apps_plans_id
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'changePlan' => [
                    'apps_plans_id' => 2,
                ],
            ],
        ]);
    }

    public function testCancelSubscription()
    {
        $subscription = Subscription::create([
            'users_id' => 1,
            'user_id' => 1,
            'companies_id' => 1,
            'apps_id' => 1,
            'apps_plans_id' => 1,
            'name' => 'Test Subscription',
        ]);

        $response = $this->graphQL('
            mutation {
                cancelSubscription(subscriptionId: ' . $subscription->id . ') {
                    id
                    is_cancelled
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'cancelSubscription' => [
                    'is_cancelled' => true,
                ],
            ],
        ]);
    }

    public function testExpireSubscription()
    {
        $subscription = Subscription::create([
            'users_id' => 1,
            'user_id' => 1,
            'companies_id' => 1,
            'apps_id' => 1,
            'apps_plans_id' => 1,
            'name' => 'Test Subscription',
        ]);

        $response = $this->graphQL('
            mutation {
                expireSubscription(subscriptionId: ' . $subscription->id . ') {
                    id
                    ends_at
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'expireSubscription' => [
                    'ends_at' => now()->toDateTimeString(),
                ],
            ],
        ]);
    }

    public function testChangeFromFreeTrial()
    {
        $subscription = Subscription::create([
            'users_id' => 1,
            'user_id' => 1,
            'companies_id' => 1,
            'apps_id' => 1,
            'apps_plans_id' => 1,
            'name' => 'Test Subscription',
            'is_freetrial' => true,
        ]);

        $response = $this->graphQL('
            mutation {
                changeFromFreeTrial(subscriptionId: ' . $subscription->id . ') {
                    id
                    is_freetrial
                    stripe_status
                    paid
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'changeFromFreeTrial' => [
                    'is_freetrial' => false,
                    'stripe_status' => 'active',
                    'paid' => true,
                ],
            ],
        ]);
    }
}