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

final class SubscriptionPricesTest extends TestCase
{
    protected Companies $company;
    protected Apps $appModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = auth()->user()->getCurrentCompany();
        $this->appModel = app(Apps::class);

        if (empty($this->appModel->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            $this->appModel->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, getenv('TEST_STRIPE_SECRET_KEY'));
        }
    }

    /**
     * TestCreatePrice.
     */
    public function testCreatePrice()
    {
        $plan = [
            'apps_id' => 1,
            'name' => 'Test plan',
            'stripe_id' => 'prod_R0llYZVFCMX0Dz',
            'free_trial_dates' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('apps_plans')->updateOrInsert(
            ['stripe_id' => $plan['stripe_id']],
            $plan
        );
        $planId = Plan::where('stripe_id', $plan['stripe_id'])->firstOrFail()->id;

        $response = $this->graphQL('
            mutation {
                createPrice(input: {
                    apps_plans_id: ' . $planId . ',
                    amount: 13.00,
                    currency: "USD",
                    interval: "year"
                }) {
                    id
                    stripe_id
                    amount
                    currency
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createPrice' => [
                    'amount' => 13.00,
                    'currency' => 'USD',
                ],
            ],
        ]);

        $this->assertDatabaseHas('apps_plans_prices', [
            'apps_plans_id' => $planId,
            'amount' => 13.00,
            'currency' => 'USD',
        ]);
    }

    /**
     * TestUpdatePrice.
     */
    public function testUpdatePrice()
    {
        $priceId = Price::firstOrFail()->id;

        $response = $this->graphQL('
            mutation {
                updatePrice(id: ' . $priceId . ', input: {
                    is_active: false
                }) {
                    id
                    stripe_id
                    is_active
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'updatePrice' => [
                    'is_active' => false,
                ],
            ],
        ]);

        $this->assertDatabaseHas('apps_plans_prices', [
            'id' => $priceId,
            'is_active' => false,
        ]);
    }
}
