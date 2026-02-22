<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class UserSubscriptionTest extends TestCase
{
    public function testGetUserCurrentSubscription(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $stripeCustomer = AppsStripeCustomer::firstOrCreate([
            'users_id' => $user->getId(),
            'companies_id' => 0,
            'apps_id' => $app->getId(),
        ]);

        $subscription = Subscription::create([
            'apps_stripe_customer_id' => $stripeCustomer->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_' . Str::random(20),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_123',
            'quantity' => 1,
        ]);

        $response = $this->graphQL('
            query {
                me {
                    id
                    currentSubscription {
                        id
                        type
                        provider
                        stripe_id
                        stripe_status
                        stripe_price
                        quantity
                    }
                }
            }
        ');

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'me' => [
                        'currentSubscription' => [
                            'id' => (string) $subscription->id,
                            'type' => 'default',
                            'stripe_status' => 'active',
                            'stripe_price' => 'price_test_123',
                        ],
                    ],
                ],
            ]);
    }

    public function testGetUserSubscriptionById(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $stripeCustomer = AppsStripeCustomer::firstOrCreate([
            'users_id' => $user->getId(),
            'companies_id' => 0,
            'apps_id' => $app->getId(),
        ]);

        $subscription = Subscription::create([
            'apps_stripe_customer_id' => $stripeCustomer->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_' . Str::random(20),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_456',
            'quantity' => 1,
        ]);

        $response = $this->graphQL('
            query($id: ID!) {
                userSubscription(id: $id) {
                    id
                    type
                    provider
                    stripe_id
                    stripe_status
                    stripe_price
                    quantity
                }
            }
        ', ['id' => $subscription->id]);

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'userSubscription' => [
                        'id' => (string) $subscription->id,
                        'type' => 'default',
                        'stripe_status' => 'active',
                        'stripe_price' => 'price_test_456',
                    ],
                ],
            ]);
    }

    public function testGetUserCurrentSubscriptionReturnsNullWhenNoSubscription(): void
    {
        $response = $this->graphQL('
            query {
                me {
                    id
                    currentSubscription {
                        id
                        stripe_status
                    }
                }
            }
        ');

        $response->assertSuccessful()
            ->assertJson([
                'data' => [
                    'me' => [
                        'currentSubscription' => null,
                    ],
                ],
            ]);
    }
}
