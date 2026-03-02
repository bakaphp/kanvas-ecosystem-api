<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class CompanySubscriptionTest extends TestCase
{
    public function testListCompanySubscriptions(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $configData = ['plan' => 'enterprise', 'seats' => 10];

        $stripeCustomer = AppsStripeCustomer::firstOrCreate([
            'users_id' => 0,
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
        ]);
        $stripeCustomer->config = $configData;
        $stripeCustomer->saveOrFail();

        $subscription = Subscription::create([
            'apps_stripe_customer_id' => $stripeCustomer->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_' . Str::random(20),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_company_123',
            'quantity' => 1,
        ]);

        $response = $this->graphQL('
            query {
                companySubscriptions {
                    data {
                        id
                        company_id
                        type
                        provider
                        stripe_id
                        stripe_status
                        status
                        plan_name
                        stripe_price
                        quantity
                        started_at
                        is_active
                        config
                    }
                }
            }
        ');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'data' => [
                    'companySubscriptions' => [
                        'data' => [
                            ['id', 'company_id', 'type', 'provider', 'stripe_id', 'stripe_status', 'status', 'plan_name', 'stripe_price', 'quantity', 'started_at', 'is_active', 'config'],
                        ],
                    ],
                ],
            ]);

        $subscriptions = $response->json('data.companySubscriptions.data');
        $found = collect($subscriptions)->firstWhere('id', (string) $stripeCustomer->id);

        $this->assertNotNull($found);
        $this->assertEquals((string) $company->getId(), $found['company_id']);
        $this->assertEquals('active', $found['stripe_status']);
        $this->assertEquals('active', $found['status']);
        $this->assertEquals('default', $found['plan_name']);
        $this->assertEquals('price_test_company_123', $found['stripe_price']);
        $this->assertTrue($found['is_active']);
        $this->assertEquals($configData, $found['config']);
    }
}
