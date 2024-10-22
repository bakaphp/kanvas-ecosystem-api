<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Subscription\Plans\Models\Plan;
use Tests\TestCase;

final class SubscriptionPlansTest extends TestCase
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
     * TestCreatePlan.
     */
    public function testCreatePlan()
    {
        $response = $this->graphQL('
            mutation {
                createPlan(input: {
                    name: "Test plan",
                    description: "This is a test plan description",
                    free_trial_dates: 15,
                    is_default: true,
                    prices: [
                    {
                        amount: 30.00
                        currency: "USD"
                        interval: "year"
                    }
                    ]
                }) {
                    id
                    name
                    stripe_id
                    free_trial_dates
                    is_default
                    created_at
                    prices {
                        amount
                        currency
                        interval
                        stripe_id
                    }
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createPlan' => [
                    'name' => 'Test plan',
                    'free_trial_dates' => 15,
                    'is_default' => true,
                    'prices' => [
                        [
                            'amount' => 30.00,
                            'currency' => 'USD',
                            'interval' => 'year',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertDatabaseHas('apps_plans', [
            'name' => 'Test plan',
            'free_trial_dates' => 15,
            'is_default' => true,
        ]);
    }

    /**
     * TestUpdatePlan.
     */
    public function testUpdatePlan()
    {
        $planId = Plan::firstOrFail()->id;

        $response = $this->graphQL('
            mutation {
                updatePlan(id: ' . $planId . ', input: {
                    name: "Updated plan",
                    description: "Updated description",
                    free_trial_dates: 42,
                    is_active: true,
                    is_default: true
                }) {
                    id
                    stripe_id
                    name
                    description
                    free_trial_dates
                    is_active
                    is_default
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'updatePlan' => [
                    'name' => 'Updated plan',
                    'description' => 'Updated description',
                    'free_trial_dates' => 42,
                    'is_active' => true,
                    'is_default' => true,
                ],
            ],
        ]);

        $this->assertDatabaseHas('apps_plans', [
            'id' => $planId,
            'name' => 'Updated plan',
            'free_trial_dates' => 42,
            'is_default' => true,
        ]);
    }

    /**
     * TestDeletePlan.
     */
    public function testDeletePlan(): void
    {

        $response = $this->graphQL('
            mutation {
                createPlan(input: {
                    name: "Test plan to delete",
                    description: "Plan to delete",
                    free_trial_dates: 15,
                    is_default: true,
                    prices: []
                }) {
                    id
                    name
                    stripe_id
                    free_trial_dates
                    is_default
                }
            }
        ');

        $planId = $response->json('data.createPlan.id');

        $deleteResponse = $this->graphQL('
            mutation {
                deletePlan(id: ' . $planId . ')
            }
        ');

        $deleteResponse->assertJson([
            'data' => [
                'deletePlan' => true,
            ],
        ]);

        $this->assertDatabaseHas('apps_plans', [
            'id' => $planId,
            'is_deleted' => 1,
        ]);
    }

    /**
     * TestListPlans.
     */
    public function testListPlans(): void
    {
        $response = $this->graphQL(
            'query {
                subscriptionPlans {
                    data
                    {
                        id
                        name
                        description
                        stripe_id
                        prices {
                            id
                            stripe_id
                            amount
                        }
                    }
                }
            }'
        );

        $response->assertJsonStructure([
            'data' => [
                'subscriptionPlans' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'stripe_id',
                            'prices' => [
                                '*' => [
                                    'id',
                                    'stripe_id',
                                    'amount',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
