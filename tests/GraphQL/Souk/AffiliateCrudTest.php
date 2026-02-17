<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

class AffiliateCrudTest extends TestCase
{
    protected $apps;
    protected $user;
    protected $company;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
    }

    public function testCreateAffiliateProgram(): void
    {
        $input = [
            'name' => 'Test Program ' . uniqid(),
            'description' => 'A test program',
            'default_commission_rate' => 15.00,
        ];

        $this->graphQL('
            mutation($input: AffiliateProgramInput!) {
                createAffiliateProgram(input: $input) {
                    id
                    name
                    description
                    slug
                    is_active
                    default_commission_rate
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAffiliateProgram' => [
                    'name' => $input['name'],
                    'description' => $input['description'],
                    'is_active' => true,
                    'default_commission_rate' => 15.00,
                ],
            ],
        ]);
    }

    public function testUpdateAffiliateProgram(): void
    {
        $programId = $this->createProgram();

        $updateInput = ['name' => 'Updated Program ' . fake()->word()];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateAffiliateProgramInput!) {
                updateAffiliateProgram(id: $id, input: $input) {
                    id
                    name
                }
            }
        ', ['id' => $programId, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateAffiliateProgram' => [
                    'name' => $updateInput['name'],
                ],
            ],
        ]);
    }

    public function testDeleteAffiliateProgram(): void
    {
        $programId = $this->createProgram();

        $this->graphQL('
            mutation($id: ID!) {
                deleteAffiliateProgram(id: $id)
            }
        ', ['id' => $programId])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteAffiliateProgram' => true]]);
    }

    public function testListAffiliatePrograms(): void
    {
        $this->createProgram();

        $this->graphQL('
            query {
                affiliatePrograms(first: 10) {
                    data {
                        id
                        name
                        slug
                        is_active
                        default_commission_rate
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'affiliatePrograms' => [
                    'data' => [['id', 'name', 'slug', 'is_active']],
                ],
            ],
        ]);
    }

    public function testCreateAffiliateTier(): void
    {
        $programId = $this->createProgram();

        $input = [
            'affiliate_program_id' => $programId,
            'name' => 'Gold Tier ' . fake()->word(),
            'level' => 1,
            'base_commission_rate' => 20.00,
        ];

        $this->graphQL('
            mutation($input: AffiliateTierInput!) {
                createAffiliateTier(input: $input) {
                    id
                    name
                    level
                    base_commission_rate
                    program {
                        id
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAffiliateTier' => [
                    'name' => $input['name'],
                    'level' => 1,
                    'base_commission_rate' => 20.00,
                    'program' => ['id' => $programId],
                ],
            ],
        ]);
    }

    public function testUpdateAffiliateTier(): void
    {
        $programId = $this->createProgram();
        $tierId = $this->createTier($programId);

        $updateInput = ['name' => 'Platinum Tier ' . fake()->word(), 'level' => 2];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateAffiliateTierInput!) {
                updateAffiliateTier(id: $id, input: $input) {
                    id
                    name
                    level
                }
            }
        ', ['id' => $tierId, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateAffiliateTier' => [
                    'name' => $updateInput['name'],
                    'level' => 2,
                ],
            ],
        ]);
    }

    public function testDeleteAffiliateTier(): void
    {
        $programId = $this->createProgram();
        $tierId = $this->createTier($programId);

        $this->graphQL('
            mutation($id: ID!) {
                deleteAffiliateTier(id: $id)
            }
        ', ['id' => $tierId])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteAffiliateTier' => true]]);
    }

    public function testListAffiliateTiers(): void
    {
        $programId = $this->createProgram();
        $this->createTier($programId);

        $this->graphQL('
            query {
                affiliateTiers(first: 10) {
                    data {
                        id
                        name
                        level
                        base_commission_rate
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'affiliateTiers' => [
                    'data' => [['id', 'name', 'level', 'base_commission_rate']],
                ],
            ],
        ]);
    }

    public function testCreateAffiliate(): void
    {
        $programId = $this->createProgram();

        $input = [
            'affiliate_program_id' => $programId,
            'name' => fake()->name(),
            'email' => fake()->email(),
            'commission_rate' => 12.50,
        ];

        $this->graphQL('
            mutation($input: AffiliateInput!) {
                createAffiliate(input: $input) {
                    id
                    name
                    email
                    commission_rate
                    status
                    program {
                        id
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAffiliate' => [
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'commission_rate' => 12.50,
                    'status' => 'pending',
                    'program' => ['id' => $programId],
                ],
            ],
        ]);
    }

    public function testUpdateAffiliate(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);

        $updateInput = [
            'name' => 'Updated Affiliate ' . fake()->word(),
            'status' => 'ACTIVE',
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateAffiliateInput!) {
                updateAffiliate(id: $id, input: $input) {
                    id
                    name
                    status
                }
            }
        ', ['id' => $affiliateId, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateAffiliate' => [
                    'name' => $updateInput['name'],
                    'status' => 'active',
                ],
            ],
        ]);
    }

    public function testDeleteAffiliate(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);

        $this->graphQL('
            mutation($id: ID!) {
                deleteAffiliate(id: $id)
            }
        ', ['id' => $affiliateId])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteAffiliate' => true]]);
    }

    public function testListAffiliates(): void
    {
        $programId = $this->createProgram();
        $this->createAffiliate($programId);

        $this->graphQL('
            query {
                affiliates(first: 10) {
                    data {
                        id
                        name
                        email
                        status
                        commission_rate
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'affiliates' => [
                    'data' => [['id', 'name', 'email', 'status', 'commission_rate']],
                ],
            ],
        ]);
    }

    public function testCreateAffiliateLink(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);

        $input = [
            'affiliate_id' => $affiliateId,
            'name' => 'Landing Page Link',
            'destination_url' => 'https://example.com/landing',
        ];

        $this->graphQL('
            mutation($input: AffiliateLinkInput!) {
                createAffiliateLink(input: $input) {
                    id
                    name
                    destination_url
                    short_code
                    is_active
                    affiliate {
                        id
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAffiliateLink' => [
                    'name' => 'Landing Page Link',
                    'destination_url' => 'https://example.com/landing',
                    'is_active' => true,
                    'affiliate' => ['id' => $affiliateId],
                ],
            ],
        ]);
    }

    public function testUpdateAffiliateLink(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);
        $linkId = $this->createLink($affiliateId);

        $updateInput = [
            'name' => 'Updated Link',
            'is_active' => false,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateAffiliateLinkInput!) {
                updateAffiliateLink(id: $id, input: $input) {
                    id
                    name
                    is_active
                }
            }
        ', ['id' => $linkId, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateAffiliateLink' => [
                    'name' => 'Updated Link',
                    'is_active' => false,
                ],
            ],
        ]);
    }

    public function testDeleteAffiliateLink(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);
        $linkId = $this->createLink($affiliateId);

        $this->graphQL('
            mutation($id: ID!) {
                deleteAffiliateLink(id: $id)
            }
        ', ['id' => $linkId])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteAffiliateLink' => true]]);
    }

    public function testListAffiliateLinks(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);
        $this->createLink($affiliateId);

        $this->graphQL('
            query {
                affiliateLinks(first: 10) {
                    data {
                        id
                        name
                        short_code
                        is_active
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'affiliateLinks' => [
                    'data' => [['id', 'name', 'short_code', 'is_active']],
                ],
            ],
        ]);
    }

    public function testCreateAffiliateConversion(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);
        $order = $this->createOrder();

        $input = [
            'affiliate_id' => $affiliateId,
            'order_id' => $order->getId(),
            'order_total' => 100.00,
            'eligible_amount' => 100.00,
            'commission_type' => 'PERCENTAGE',
            'commission_rate' => 10.00,
            'commission_amount' => 10.00,
        ];

        $this->graphQL('
            mutation($input: AffiliateConversionInput!) {
                createAffiliateConversion(input: $input) {
                    id
                    order_total
                    commission_amount
                    status
                    affiliate {
                        id
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAffiliateConversion' => [
                    'status' => 'pending',
                    'affiliate' => ['id' => $affiliateId],
                ],
            ],
        ]);
    }

    public function testUpdateAffiliateConversion(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);
        $conversionId = $this->createConversion($affiliateId);

        $updateInput = [
            'status' => 'CONFIRMED',
            'confirmed' => true,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateAffiliateConversionInput!) {
                updateAffiliateConversion(id: $id, input: $input) {
                    id
                    status
                    confirmed
                }
            }
        ', ['id' => $conversionId, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateAffiliateConversion' => [
                    'status' => 'confirmed',
                    'confirmed' => true,
                ],
            ],
        ]);
    }

    public function testDeleteAffiliateConversion(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);
        $conversionId = $this->createConversion($affiliateId);

        $this->graphQL('
            mutation($id: ID!) {
                deleteAffiliateConversion(id: $id)
            }
        ', ['id' => $conversionId])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteAffiliateConversion' => true]]);
    }

    public function testListAffiliateConversions(): void
    {
        $this->graphQL('
            query {
                affiliateConversions(first: 10) {
                    data {
                        id
                        status
                        commission_amount
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'affiliateConversions' => [
                    'data',
                ],
            ],
        ]);
    }

    public function testCreateAffiliateCommissionPayout(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);

        $input = [
            'affiliate_id' => $affiliateId,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'total_conversions' => 10,
            'total_order_value' => 1000.00,
            'gross_commission' => 100.00,
            'net_commission' => 95.00,
            'fees' => 5.00,
        ];

        $this->graphQL('
            mutation($input: AffiliateCommissionPayoutInput!) {
                createAffiliateCommissionPayout(input: $input) {
                    id
                    period_start
                    period_end
                    total_conversions
                    gross_commission
                    net_commission
                    payout_status
                    affiliate {
                        id
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAffiliateCommissionPayout' => [
                    'total_conversions' => 10,
                    'gross_commission' => 100.00,
                    'net_commission' => 95.00,
                    'payout_status' => 'pending_approval',
                    'affiliate' => ['id' => $affiliateId],
                ],
            ],
        ]);
    }

    public function testUpdateAffiliateCommissionPayout(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);
        $payoutId = $this->createPayout($affiliateId);

        $updateInput = [
            'payout_status' => 'APPROVED',
            'approval_notes' => 'Looks good',
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateAffiliateCommissionPayoutInput!) {
                updateAffiliateCommissionPayout(id: $id, input: $input) {
                    id
                    payout_status
                    approval_notes
                }
            }
        ', ['id' => $payoutId, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateAffiliateCommissionPayout' => [
                    'payout_status' => 'approved',
                    'approval_notes' => 'Looks good',
                ],
            ],
        ]);
    }

    public function testDeleteAffiliateCommissionPayout(): void
    {
        $programId = $this->createProgram();
        $affiliateId = $this->createAffiliate($programId);
        $payoutId = $this->createPayout($affiliateId);

        $this->graphQL('
            mutation($id: ID!) {
                deleteAffiliateCommissionPayout(id: $id)
            }
        ', ['id' => $payoutId])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteAffiliateCommissionPayout' => true]]);
    }

    public function testListAffiliateCommissionPayouts(): void
    {
        $this->graphQL('
            query {
                affiliateCommissionPayouts(first: 10) {
                    data {
                        id
                        payout_status
                        net_commission
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'affiliateCommissionPayouts' => [
                    'data',
                ],
            ],
        ]);
    }

    private function createProgram(): string
    {
        $response = $this->graphQL('
            mutation($input: AffiliateProgramInput!) {
                createAffiliateProgram(input: $input) { id }
            }
        ', [
            'input' => [
                'name' => 'Test Program ' . uniqid(),
                'default_commission_rate' => 10.00,
            ],
        ])->assertSuccessful();

        return $response->json('data.createAffiliateProgram.id');
    }

    private function createTier(string $programId): string
    {
        $response = $this->graphQL('
            mutation($input: AffiliateTierInput!) {
                createAffiliateTier(input: $input) { id }
            }
        ', [
            'input' => [
                'affiliate_program_id' => $programId,
                'name' => 'Test Tier ' . uniqid(),
                'level' => 1,
                'base_commission_rate' => 15.00,
            ],
        ])->assertSuccessful();

        return $response->json('data.createAffiliateTier.id');
    }

    private function createAffiliate(string $programId): string
    {
        $response = $this->graphQL('
            mutation($input: AffiliateInput!) {
                createAffiliate(input: $input) { id }
            }
        ', [
            'input' => [
                'affiliate_program_id' => $programId,
                'name' => fake()->name(),
                'email' => fake()->email(),
                'commission_rate' => 10.00,
            ],
        ])->assertSuccessful();

        return $response->json('data.createAffiliate.id');
    }

    private function createLink(string $affiliateId): string
    {
        $response = $this->graphQL('
            mutation($input: AffiliateLinkInput!) {
                createAffiliateLink(input: $input) { id }
            }
        ', [
            'input' => [
                'affiliate_id' => $affiliateId,
                'name' => 'Test Link ' . fake()->word(),
                'destination_url' => 'https://example.com/test',
            ],
        ])->assertSuccessful();

        return $response->json('data.createAffiliateLink.id');
    }

    private function createConversion(string $affiliateId): string
    {
        $order = $this->createOrder();

        $response = $this->graphQL('
            mutation($input: AffiliateConversionInput!) {
                createAffiliateConversion(input: $input) { id }
            }
        ', [
            'input' => [
                'affiliate_id' => $affiliateId,
                'order_id' => $order->getId(),
                'order_total' => 100.00,
                'eligible_amount' => 100.00,
                'commission_type' => 'PERCENTAGE',
                'commission_rate' => 10.00,
                'commission_amount' => 10.00,
            ],
        ])->assertSuccessful();

        return $response->json('data.createAffiliateConversion.id');
    }

    private function createPayout(string $affiliateId): string
    {
        $response = $this->graphQL('
            mutation($input: AffiliateCommissionPayoutInput!) {
                createAffiliateCommissionPayout(input: $input) { id }
            }
        ', [
            'input' => [
                'affiliate_id' => $affiliateId,
                'period_start' => '2025-01-01',
                'period_end' => '2025-01-31',
                'total_conversions' => 5,
                'total_order_value' => 500.00,
                'gross_commission' => 50.00,
                'net_commission' => 47.50,
            ],
        ])->assertSuccessful();

        return $response->json('data.createAffiliateCommissionPayout.id');
    }

    private function createOrder(): Order
    {
        $people = People::factory()
            ->withAppId($this->apps->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->user->getId())
            ->create();

        return Order::factory()
            ->withCompanyId($this->company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
