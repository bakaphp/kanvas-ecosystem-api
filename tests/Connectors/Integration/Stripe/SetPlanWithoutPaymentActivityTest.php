<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Stripe;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Workflows\Activities\SetPlanWithoutPaymentActivity;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

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
            'apps_id' => $this->appModel->id,
            'name' => 'Test Plan',
            'payment_interval' => 'year',
            'description' => 'This is a test plan.',
            'stripe_id' => 'prod_R2hvq1l4dBI0v2',
            'free_trial_dates' => 14,
            'is_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('apps_plans')->updateOrInsert(
            ['stripe_id' => $plan['stripe_id']],
            $plan
        );
        $planId = DB::table('apps_plans')->where('stripe_id', $plan['stripe_id'])->value('id');
        $price = [
                'apps_plans_id' => $planId,
                'stripe_id' => 'price_1QAcVrBwyV21ueMMngenEy2U',
                'amount' => 100.00,
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
        $defaultPlan = Plan::where('apps_id', $this->appModel->id)->firstOrFail();
        $this->appModel->default_apps_plan_id = $defaultPlan->id;
        $this->appModel->save();

        $params = [];
        $response = $this->activity->execute($this->user, $this->appModel, $params);
        $this->assertIsArray($response);
        $this->assertEquals('success', $response['status']);
        foreach (['user_id', 'company_id', 'default_plan', 'trial_ends_at'] as $key) {
            $this->assertArrayHasKey($key, $response);
        }
    }
}
