<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Enums\AppEnums;
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

        $this->appModel->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, $this->requireStripeTestKey());
    }

    private function appKeyHeader(): array
    {
        return [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $this->appModel->keys()->first()->client_secret_id,
        ];
    }

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
        ', [], [], $this->appKeyHeader());

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
        ', [], [], $this->appKeyHeader());

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

    public function testDeletePlanWithPricesArchivesOnStripeAndSoftDeletesLocally(): void
    {
        // Plan WITH a price — covers the production case where Stripe refuses
        // hard-delete on products that have prices. Mutation must archive
        // (active: false) on Stripe and soft-delete locally regardless.
        $response = $this->graphQL('
            mutation {
                createPlan(input: {
                    name: "Plan with price to delete",
                    description: "must archive on Stripe, soft-delete locally",
                    free_trial_dates: 0,
                    is_default: false,
                    prices: [
                        { amount: 12.00, currency: "USD", interval: "month" }
                    ]
                }) {
                    id
                    stripe_id
                }
            }
        ', [], [], $this->appKeyHeader());

        $planId = $response->json('data.createPlan.id');

        $deleteResponse = $this->graphQL('
            mutation {
                deletePlan(id: ' . $planId . ')
            }
        ', [], [], $this->appKeyHeader());

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

    public function testArchivePlanWithIsActiveOnlyDoesNotWipeOtherFields(): void
    {
        $createResponse = $this->graphQL('
            mutation {
                createPlan(input: {
                    name: "Plan To Archive",
                    description: "preserve me on archive",
                    free_trial_dates: 21,
                    is_default: false,
                    prices: []
                }) {
                    id
                    name
                    description
                    free_trial_dates
                }
            }
        ', [], [], $this->appKeyHeader());

        $planId = $createResponse->json('data.createPlan.id');

        $response = $this->graphQL('
            mutation {
                updatePlan(id: ' . $planId . ', input: { is_active: false }) {
                    id
                    name
                    description
                    free_trial_dates
                    is_active
                }
            }
        ', [], [], $this->appKeyHeader());

        $response->assertJson([
            'data' => [
                'updatePlan' => [
                    'name' => 'Plan To Archive',
                    'description' => 'preserve me on archive',
                    'free_trial_dates' => 21,
                    'is_active' => false,
                ],
            ],
        ]);
    }

    public function testCreatePlanRejectedWithoutAppKey(): void
    {
        $response = $this->graphQL('
            mutation {
                createPlan(input: {
                    name: "Unauthorized plan",
                    description: "should fail",
                    free_trial_dates: 0,
                    is_default: false,
                    prices: []
                }) { id }
            }
        ');

        $this->assertNotEmpty($response->json('errors'));
        $this->assertNull($response->json('data.createPlan'));
    }
}
