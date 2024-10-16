<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Connectors\Stripe\Workflows\Activities\SetPlanWithoutPaymentActivity;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;

final class SetPlanWithoutPaymentActivityTest extends TestCase
{
    protected Companies $company;
    protected Apps $appModel;
    protected SetPlanWithoutPaymentActivity $activity;
    protected UserInterface $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = Users::factory()->create();
        $this->company = Companies::factory()->create([
            'users_id' => $this->user->id,
        ]);
        $this->appModel = app(Apps::class);

        if (empty($this->appModel->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            $this->appModel->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, getenv('TEST_STRIPE_SECRET_KEY'));
        }

        $this->seedAppPlansPrices();
        $this->activity = new SetPlanWithoutPaymentActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
    }

    protected function seedAppPlansPrices()
    {
        // Define the data you want to insert
        $plan = [
            'id' => 1,
            'apps_id' => $this->appModel->id,
            'name' => 'Test Plan',
            'description' => 'This is a test plan.',
            'stripe_id' => 'prod_QnFvCpGitBFjvY',
            'free_trial_dates' => 14,
            'is_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('apps_plans')->updateOrInsert(
            ['id' => $plan['id']],
            $plan
        );
        DB::table('apps')->where('id', $this->appModel->id)->update(['default_apps_plan_id' => $plan['id']]);

        $price = [
                'apps_plans_id' => 1,
                'stripe_id' => 'price_1Q1NGrBwyV21ueMMkJR2eA8U',
                'amount' => 5.00,
                'currency' => 'USD',
                'interval' => 'yearly',
                'is_default' => 1,
                'created_at' => now(),
                'updated_at' => now(),
        ];

        DB::table('apps_plans_prices')->updateOrInsert(
            // Check if a record with the same `stripe_id` exists
            ['stripe_id' => $price['stripe_id']],
            // If it doesn't exist, insert the entire array
            $price
        );
    }

    public function testSetPlanWithoutPayment()
    {
        $params = [];
        $response = $this->activity->execute($this->user, $this->appModel, $params);

        $this->assertIsArray($response);
        $this->assertEquals('success', $response['status']);
    }
}
