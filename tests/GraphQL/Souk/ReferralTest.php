<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Souk\Loyalty\Factories\LoyaltyProgramFactory;
use Kanvas\Souk\Referrals\Actions\GenerateReferralCodeAction;
use Kanvas\Souk\Referrals\Factories\ReferralRedemptionFactory;
use Kanvas\Souk\Referrals\Models\ReferralCode;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ReferralTest extends TestCase
{
    private ReferralCode $referralCode;
    private Users $referrer;
    private Users $referee1;
    private Users $referee2;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $this->referrer = auth()->user();
        $company = $this->referrer->getCurrentCompany();

        // Create loyalty program for referral code
        $loyaltyProgram = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        // Create referral code
        $generateAction = new GenerateReferralCodeAction(
            $this->referrer,
            $loyaltyProgram,
            $app,
            500, // referrer reward
            100, // referee reward
            10.0 // referee discount
        );
        $this->referralCode = $generateAction->execute();

        // Create test referee users
        $this->referee1 = Users::factory()->create();
        $this->referee2 = Users::factory()->create();

        // Create some referral redemptions
        ReferralRedemptionFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->completed()
            ->create([
                'referral_codes_id' => $this->referralCode->id,
                'referrer_user_id' => $this->referrer->id,
                'referee_user_id' => $this->referee1->id,
                'referrer_points_awarded' => 500,
                'referee_discount_amount' => 10.0,
            ]);

        ReferralRedemptionFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->completed()
            ->create([
                'referral_codes_id' => $this->referralCode->id,
                'referrer_user_id' => $this->referrer->id,
                'referee_user_id' => $this->referee2->id,
                'referrer_points_awarded' => 500,
                'referee_discount_amount' => 10.0,
            ]);
    }

    /**
     * Test getting my referral info (current user's referral data)
     */
    public function testGetMyReferralInfo(): void
    {
        $response = $this->graphQL('
            query {
                myReferralInfo {
                    referral_code
                    total_points_earned
                    total_referrals
                    referred_users {
                        id
                        name
                        email
                        points_earned
                        discount_applied
                        referred_at
                    }
                }
            }
        ');

        $response->assertSuccessful();

        $response->assertJsonStructure([
            'data' => [
                'myReferralInfo' => [
                    'referral_code',
                    'total_points_earned',
                    'total_referrals',
                    'referred_users' => [
                        '*' => [
                            'id',
                            'name',
                            'email',
                            'points_earned',
                            'discount_applied',
                            'referred_at',
                        ],
                    ],
                ],
            ],
        ]);

        $data = $response->json('data.myReferralInfo');

        // Verify referral code
        $this->assertEquals($this->referralCode->code, $data['referral_code']);

        // Verify total points (500 * 2 referrals)
        $this->assertEquals(1000, $data['total_points_earned']);

        // Verify total referrals
        $this->assertEquals(2, $data['total_referrals']);

        // Verify referred users
        $this->assertCount(2, $data['referred_users']);

        // Verify each referred user has correct data
        $referredEmails = collect($data['referred_users'])->pluck('email')->toArray();
        $this->assertContains($this->referee1->email, $referredEmails);
        $this->assertContains($this->referee2->email, $referredEmails);

        // Verify discount was applied
        foreach ($data['referred_users'] as $referredUser) {
            $this->assertEquals(10.0, $referredUser['discount_applied']);
            $this->assertEquals(500, $referredUser['points_earned']);
        }
    }

    /**
     * Test getting referral info when user has no referral code
     */
    public function testGetMyReferralInfoNoCode(): void
    {
        $newUser = Users::factory()->create();

        $response = $this->actingAs($newUser)->graphQL('
            query {
                myReferralInfo {
                    referral_code
                    total_points_earned
                    total_referrals
                }
            }
        ');

        $response->assertSuccessful();

        // Should return null when no referral code exists
        $this->assertNull($response->json('data.myReferralInfo'));
    }

    /**
     * Test viewing referral code usages list
     */
    public function testViewReferralCodeUsages(): void
    {
        $app = app(Apps::class);

        $response = $this->graphQL('
            query($code: String!) {
                referralCodeUsages(code: $code) {
                    paginatorInfo {
                        count
                        total
                    }
                    data {
                        id
                        referrer_user_id
                        referee_user_id
                        referrer_points_awarded
                        referee_discount_amount
                        status
                    }
                }
            }
        ', [
            'code' => $this->referralCode->code,
        ], []);

        $response->assertSuccessful();

        $usagesData = $response->json('data.referralCodeUsages');

        // Verify we have 2 completed usages
        $this->assertEquals(2, $usagesData['paginatorInfo']['count']);
        $this->assertEquals(2, $usagesData['paginatorInfo']['total']);

        // Verify each usage has correct data
        foreach ($usagesData['data'] as $usage) {
            $this->assertEquals('completed', $usage['status']);
            $this->assertEquals($this->referrer->id, $usage['referrer_user_id']);
            $this->assertEquals(500, $usage['referrer_points_awarded']);
            $this->assertEquals(10.0, $usage['referee_discount_amount']);
        }
    }

    /**
     * Test updating referral code to deactivate it
     */
    public function testUpdateReferralCodeDeactivate(): void
    {
        $app = app(Apps::class);

        // Verify it's currently active
        $this->assertTrue($this->referralCode->is_active);

        $response = $this->graphQL('
            mutation($id: ID!, $input: UpdateReferralCodeInput!) {
                updateReferralCode(id: $id, input: $input) {
                    id
                    code
                    is_active
                }
            }
        ', [
            'id' => $this->referralCode->id,
            'input' => [
                'is_active' => false,
            ],
        ], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $response->assertSuccessful();

        $updatedCode = $response->json('data.updateReferralCode');
        $this->assertEquals($this->referralCode->code, $updatedCode['code']);
        $this->assertFalse($updatedCode['is_active']);

        // Verify in database
        $this->referralCode->refresh();
        $this->assertFalse($this->referralCode->is_active);
    }

    /**
     * Test updating non-existent referral code
     */
    public function testUpdateNonExistentReferralCode(): void
    {
        $app = app(Apps::class);

        $response = $this->graphQL('
            mutation($id: ID!, $input: UpdateReferralCodeInput!) {
                updateReferralCode(id: $id, input: $input) {
                    id
                    code
                }
            }
        ', [
            'id' => 99999,
            'input' => [
                'is_active' => false,
            ],
        ], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $response->assertJsonPath('errors.0.message', 'Referral code not found');
    }

    /**
     * Test updating referral code fails with mismatched user_id
     */
    public function testUpdateReferralCodeFailsWithMismatchedUserId(): void
    {
        $app = app(Apps::class);
        $otherUser = Users::factory()->create();

        $response = $this->graphQL('
            mutation($id: ID!, $input: UpdateReferralCodeInput!) {
                updateReferralCode(id: $id, input: $input) {
                    id
                    code
                }
            }
        ', [
            'id' => $this->referralCode->id,
            'input' => [
                'code' => 'NEWCODE',
                'user_id' => $otherUser->id, // Wrong user
            ],
        ], [], [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $response->assertJsonPath('errors.0.message', 'Referral code does not belong to the specified user');
    }
}
