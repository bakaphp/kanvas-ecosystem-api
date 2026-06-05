<?php

declare(strict_types=1);

namespace Tests\GraphQL\Subscription;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Subscription\Plans\Models\Plan;
use Kanvas\Subscription\Prices\Repositories\PriceRepository;
use Kanvas\Users\Models\Users;
use Laravel\Cashier\Subscription;
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
        $this->appModel->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, $this->requireStripeTestKey());

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
                'interval' => 'yearly',
                'is_default' => 1,
                'created_at' => now(),
            ],
            [
                'apps_plans_id' => 2,
                'stripe_id' => 'price_1Q1NGrBwyV21ueMMkJR2eA8U',
                'amount' => 5.00,
                'currency' => 'USD',
                'interval' => 'yearly',
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

    /**
     * Subscribe a brand-new user/company to the given price so each lifecycle test
     * starts from a clean slate (the "company already has a subscription" guard would
     * otherwise trip when methods share the default company).
     *
     * @return array{0: Users, 1: Subscription}
     */
    private function subscribeFreshUser(int $priceId): array
    {
        $user = $this->createUser();
        $this->actingAs($user, 'api');
        /** @var Companies $company */
        $company = $user->getCurrentCompany();
        $paymentMethod = $this->createPaymentMethod();

        $this->graphQL('
            mutation {
                createSubscription(input: {
                    apps_plans_prices_id: ' . $priceId . ',
                    payment_method_id: "' . $paymentMethod . '"
                }) {
                    id
                    stripe_id
                    stripe_status
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ])->assertSuccessful();

        $subscription = $company->getStripeAccount($this->appModel)
            ->subscriptions()->where('type', 'default')->first();

        $this->assertNotNull($subscription, 'createSubscription should persist a subscription row');

        return [$user, $subscription];
    }

    public function testCreateSubscription()
    {
        $paymentMethod = $this->createPaymentMethod();
        $user = auth()->user();

        $response = $this->graphQL('
            mutation {
                createSubscription(input: {
                    apps_plans_prices_id: ' . $this->price->getId() . ' , #Basic
                    payment_method_id: "' . $paymentMethod . '"
                }) {
                    id
                    stripe_id
                    stripe_status
                    trial_ends_at
                    items {
                        id
                        stripe_id
                        stripe_product
                        stripe_product_name
                        stripe_price
                    }
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ]);

        $response->assertJson([
            'data' => [
                'createSubscription' => [
                   'stripe_status' => 'trialing',
                ],
            ],
        ]);
    }

    public function testCreateSubscriptionWithoutTrial()
    {
        // Use a fresh user so there's no pre-existing trialing subscription
        $freshUser = $this->createUser();
        $this->actingAs($freshUser, 'api');

        $paymentMethod = $this->createPaymentMethod();
        $user = auth()->user();

        $plan = [
            'apps_id' => 1,
            'name' => 'Test without trial',
            'stripe_id' => 'prod_R0llYZVFCMX0Dz',
            'free_trial_dates' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $planId = DB::table('apps_plans')->insertGetId($plan);

        $price = [
            'apps_plans_id' => $planId,
            'stripe_id' => 'price_1Q8kDZBwyV21ueMMq6ZDUKqI',
            'amount' => 38.00,
            'currency' => 'USD',
            'interval' => 'yearly',
            'is_default' => 0,
            'created_at' => now(),
        ];

        DB::table('apps_plans_prices')->updateOrInsert(
            ['stripe_id' => $price['stripe_id']],
            $price
        );
        $priceId = DB::table('apps_plans_prices')->where('stripe_id', $price['stripe_id'])->value('id');

        $response = $this->graphQL('
            mutation {
                createSubscription(input: {
                    apps_plans_prices_id: ' . $priceId . ' , #without trial
                    payment_method_id: "' . $paymentMethod . '"
                }) {
                    id
                    stripe_id
                    stripe_status
                    trial_ends_at
                    items {
                        id
                        stripe_id
                        stripe_product
                        stripe_product_name
                        stripe_price
                    }
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
                payment_method_id: "' . $paymentMethod . '"
            }) {
                id
                stripe_id
                stripe_status
            }
        }
    ', [], [], [
        'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
    ]);

        $newPriceId = DB::table('apps_plans_prices')
            ->where('apps_plans_id', '!=', $this->price->apps_plans_id)
            ->value('id');
        $response = $this->graphQL('
            mutation {
                updateSubscription(input: {
                apps_plans_prices_id: ' . $newPriceId . ' ,
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
                    'stripe_status' => 'trialing',
                ],
            ],
        ]);
    }

    public function testListSubscription()
    {
        $user = auth()->user();
        $paymentMethod = $this->createPaymentMethod();

        $response = $this->graphQL('
        mutation {
            createSubscription(input: {
                apps_plans_prices_id: ' . $this->price->getId() . ' , #Basic
                payment_method_id: "' . $paymentMethod . '"
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
            query {
                companySubscriptions {
                    data {
                        id
                        stripe_id
                        stripe_status
                    }
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ]);

        $response->assertJson([
            'data' => [
                'companySubscriptions' => [
                    'data' => [
                        [
                            'stripe_status' => 'trialing',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testCreateSubscriptionThrowsWhenCompanyAlreadyHasActiveSubscription()
    {
        $freshUser = $this->createUser();
        $this->actingAs($freshUser, 'api');

        $paymentMethod = $this->createPaymentMethod();
        $user = auth()->user();

        $this->graphQL('
            mutation {
                createSubscription(input: {
                    apps_plans_prices_id: ' . $this->price->getId() . ',
                    payment_method_id: "' . $paymentMethod . '"
                }) {
                    id
                    stripe_status
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ])->assertJson([
            'data' => [
                'createSubscription' => [
                    'stripe_status' => 'trialing',
                ],
            ],
        ]);

        $secondPaymentMethod = $this->createPaymentMethod();
        $response = $this->graphQL('
            mutation {
                createSubscription(input: {
                    apps_plans_prices_id: ' . $this->price->getId() . ',
                    payment_method_id: "' . $secondPaymentMethod . '"
                }) {
                    id
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ]);

        $this->assertNotNull($response->json('errors'));
        $this->assertStringContainsString(
            'already has an active subscription',
            $response->json('errors.0.message') ?? ''
        );
    }

    public function testCancelSubscription()
    {
        $user = auth()->user();
        $paymentMethod = $this->createPaymentMethod();

        $response = $this->graphQL('
        mutation {
            createSubscription(input: {
                apps_plans_prices_id: ' . $this->price->getId() . ' , #Basic
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

        $subscription = $this->company->getStripeAccount($this->appModel)
            ->subscriptions()->where('type', 'default')->first();

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

    public function testUpgradeSubscription(): void
    {
        $cheaper = PriceRepository::getByStripeId('price_1Q1NGrBwyV21ueMMkJR2eA8U', $this->appModel);
        $pricier = PriceRepository::getByStripeId('price_1Q11XeBwyV21ueMMd6yZ4Tl5', $this->appModel);
        $this->assertGreaterThan(
            $cheaper->amount,
            $pricier->amount,
            'Fixture sanity: the upgrade target must cost more than the starting price'
        );

        [$user, $subscription] = $this->subscribeFreshUser($cheaper->getId());
        $this->assertEquals($cheaper->stripe_id, $subscription->stripe_price);

        $this->graphQL('
            mutation {
                updateSubscription(input: {
                    apps_plans_prices_id: ' . $pricier->getId() . '
                }) {
                    id
                    stripe_id
                    stripe_price
                    stripe_status
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ])->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateSubscription' => [
                    'stripe_price' => $pricier->stripe_id,
                ],
            ],
        ]);
    }

    public function testDowngradeSubscription(): void
    {
        $cheaper = PriceRepository::getByStripeId('price_1Q1NGrBwyV21ueMMkJR2eA8U', $this->appModel);
        $pricier = PriceRepository::getByStripeId('price_1Q11XeBwyV21ueMMd6yZ4Tl5', $this->appModel);
        $this->assertGreaterThan(
            $cheaper->amount,
            $pricier->amount,
            'Fixture sanity: the starting price must cost more than the downgrade target'
        );

        [$user, $subscription] = $this->subscribeFreshUser($pricier->getId());
        $this->assertEquals($pricier->stripe_id, $subscription->stripe_price);

        $this->graphQL('
            mutation {
                updateSubscription(input: {
                    apps_plans_prices_id: ' . $cheaper->getId() . '
                }) {
                    id
                    stripe_id
                    stripe_price
                    stripe_status
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ])->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateSubscription' => [
                    'stripe_price' => $cheaper->stripe_id,
                ],
            ],
        ]);
    }

    public function testReactivateSubscription(): void
    {
        [$user, $subscription] = $this->subscribeFreshUser($this->price->getId());

        $this->graphQL('
            mutation {
                cancelSubscription(id: ' . $subscription->id . ')
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ])->assertJson([
            'data' => [
                'cancelSubscription' => true,
            ],
        ]);

        $this->graphQL('
            mutation {
                reactivateSubscription(id: ' . $subscription->id . ')
            }
        ', [], [], [
            'X-Kanvas-Location' => $user->getCurrentBranch()->uuid,
        ])->assertJson([
            'data' => [
                'reactivateSubscription' => true,
            ],
        ]);

        /** @var Companies $company */
        $company = $user->getCurrentCompany();
        $reactivated = $company->getStripeAccount($this->appModel)
            ->subscriptions()->where('id', $subscription->id)->first();

        $this->assertNull(
            $reactivated->ends_at,
            'A resumed subscription within its grace period should no longer have an end date'
        );
    }
}
