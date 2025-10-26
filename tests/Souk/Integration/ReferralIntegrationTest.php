<?php

declare(strict_types=1);

namespace Tests\Souk\Integration;

use Kanvas\Souk\Loyalty\Factories\LoyaltyProgramFactory;
use Kanvas\Souk\Referrals\Factories\ReferralCodeFactory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ReferralIntegrationTest extends TestCase
{
    /**
     * Test creating a referral code for a user.
     */
    public function testCreateReferralCode(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $code = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $this->assertNotNull($code->id);
        $this->assertEquals($referrer->id, $code->users_id);
        $this->assertTrue($code->is_active);
        $this->assertEquals(0, $code->current_uses);
    }

    /**
     * Test referral code with max uses constraint.
     */
    public function testReferralCodeWithMaxUses(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $code = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->withMaxUses(10)
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $this->assertEquals(10, $code->max_uses);
        $this->assertEquals(0, $code->current_uses);
    }

    /**
     * Test multiple referral codes for same user.
     */
    public function testMultipleReferralCodesPerUser(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $code1 = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $code2 = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $this->assertNotEquals($code1->code, $code2->code);
        $this->assertEquals($referrer->id, $code1->users_id);
        $this->assertEquals($referrer->id, $code2->users_id);
    }

    /**
     * Test expired referral code.
     */
    public function testExpiredReferralCode(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $expired = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->expired()
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $this->assertNotNull($expired->expires_at);
        $this->assertTrue($expired->expires_at < now());
    }

    /**
     * Test inactive referral code.
     */
    public function testInactiveReferralCode(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $inactive = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->inactive()
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $this->assertFalse($inactive->is_active);
    }

    /**
     * Test referral code with multiple uses enabled.
     */
    public function testMultipleUsesStrategy(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $code = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->multipleUses()
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $this->assertEquals('multiple', $code->strategy);
        $this->assertNotNull($code->max_uses);
        $this->assertTrue($code->max_uses > 0);
    }

    /**
     * Test referral code at max capacity.
     */
    public function testReferralCodeAlmostMaxed(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $code = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->almostMaxed(10)
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $this->assertEquals(10, $code->max_uses);
        $this->assertEquals(9, $code->current_uses);
        $this->assertEquals(1, $code->max_uses - $code->current_uses);
    }

    /**
     * Test referral rewards configuration.
     */
    public function testReferralRewards(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $code = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->create([
                'loyalty_programs_id' => $program->id,
                'referrer_reward' => 1000,
                'referee_reward' => 250,
                'referee_discount' => 15.0,
            ]);

        $this->assertEquals(1000, $code->referrer_reward);
        $this->assertEquals(250, $code->referee_reward);
        $this->assertEquals(15.0, $code->referee_discount);
    }

    /**
     * Test referral code unique constraint.
     */
    public function testReferralCodeUnique(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $code1 = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $code2 = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        // Both codes should be unique
        $this->assertNotEquals($code1->code, $code2->code);
    }

    /**
     * Test referral code with metadata.
     */
    public function testReferralCodeMetadata(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $metadata = [
            'campaign' => 'summer_2025',
            'channel' => 'email',
            'notes' => 'VIP customer referral program',
        ];

        $code = ReferralCodeFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->create([
                'loyalty_programs_id' => $program->id,
                'meta' => $metadata,
            ]);

        $this->assertIsArray($code->meta);
        $this->assertEquals('summer_2025', $code->meta['campaign']);
        $this->assertEquals('email', $code->meta['channel']);
    }
}
