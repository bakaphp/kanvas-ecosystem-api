<?php

declare(strict_types=1);

namespace Tests\Souk\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Loyalty\Factories\LoyaltyOfferFactory;
use Kanvas\Souk\Loyalty\Factories\LoyaltyProgramFactory;
use Kanvas\Souk\Loyalty\Factories\LoyaltyTierFactory;
use Tests\TestCase;

final class LoyaltyIntegrationTest extends TestCase
{
    /**
     * Test creating a complete loyalty program with tiers and offers.
     */
    public function testCreateLoyaltyProgramWithTiers(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        // Create loyalty program
        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $this->assertNotNull($program->id);
        $this->assertTrue($program->is_active);
        // points_per_dollar is randomized between 0.5 and 2.0, so we just verify it's a positive decimal
        $this->assertGreaterThan(0, $program->points_per_dollar);

        // Create tiers
        $bronze = LoyaltyTierFactory::new()->bronze()->create([
            'loyalty_programs_id' => $program->id,
            'companies_id' => $company->getId(),
        ]);

        $silver = LoyaltyTierFactory::new()->silver()->create([
            'loyalty_programs_id' => $program->id,
            'companies_id' => $company->getId(),
        ]);

        $gold = LoyaltyTierFactory::new()->gold()->create([
            'loyalty_programs_id' => $program->id,
            'companies_id' => $company->getId(),
        ]);

        // Verify tier hierarchy
        $this->assertEquals(1, $bronze->level);
        $this->assertEquals(2, $silver->level);
        $this->assertEquals(3, $gold->level);

        $this->assertLessThan($silver->min_points, $bronze->min_points);
        $this->assertLessThan($gold->min_points, $silver->min_points);

        // Verify earning multipliers increase
        $this->assertLessThan($silver->earning_multiplier, $bronze->earning_multiplier);
        $this->assertLessThan($gold->earning_multiplier, $silver->earning_multiplier);
    }

    /**
     * Test creating offers for different triggers.
     */
    public function testCreateLoyaltyOffersWithDifferentTriggers(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        // Create offers with different triggers
        $firstPurchaseOffer = LoyaltyOfferFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->points()
            ->create([
                'loyalty_programs_id' => $program->id,
                'trigger_type' => 'first_purchase',
            ]);

        $birthdayOffer = LoyaltyOfferFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->discount()
            ->birthdayTrigger()
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $tierUpgradeOffer = LoyaltyOfferFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->tierUpgradeTrigger()
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        // Verify offers
        $this->assertEquals('first_purchase', $firstPurchaseOffer->trigger_type);
        $this->assertEquals('points', $firstPurchaseOffer->offer_type);

        $this->assertEquals('birthday', $birthdayOffer->trigger_type);
        $this->assertEquals('discount', $birthdayOffer->offer_type);

        $this->assertEquals('tier_upgrade', $tierUpgradeOffer->trigger_type);
    }

    /**
     * Test offer status lifecycle.
     */
    public function testOfferStatusLifecycle(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        // Create offer in draft
        $offer = LoyaltyOfferFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->draft()
            ->create([
                'loyalty_programs_id' => $program->id,
            ]);

        $this->assertEquals('draft', $offer->status);

        // Activate
        $offer->update(['status' => 'active']);
        $this->assertEquals('active', $offer->status);

        // Pause
        $offer->update(['status' => 'paused']);
        $this->assertEquals('paused', $offer->status);

        // Archive
        $offer->update(['status' => 'archived']);
        $this->assertEquals('archived', $offer->status);
    }

    /**
     * Test multiple programs and tier memberships.
     */
    public function testMultipleLoyaltyPrograms(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        // Create multiple programs
        $program1 = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Store Rewards',
            ]);

        $program2 = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'VIP Program',
            ]);

        // Create tiers for each
        $tier1_1 = LoyaltyTierFactory::new()->silver()->create([
            'loyalty_programs_id' => $program1->id,
            'companies_id' => $company->getId(),
        ]);

        $tier2_1 = LoyaltyTierFactory::new()->gold()->create([
            'loyalty_programs_id' => $program2->id,
            'companies_id' => $company->getId(),
        ]);

        // Verify isolation
        $this->assertEquals($program1->id, $tier1_1->loyalty_programs_id);
        $this->assertEquals($program2->id, $tier2_1->loyalty_programs_id);
        $this->assertNotEquals($tier1_1->loyalty_programs_id, $tier2_1->loyalty_programs_id);
    }

    /**
     * Test program with referral enabled.
     */
    public function testLoyaltyProgramWithReferralEnabled(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withReferral()
            ->create();

        $this->assertTrue($program->referral_enabled);
        $this->assertNotNull($program->referral_config);
        $this->assertIsArray($program->referral_config);
        $this->assertArrayHasKey('referrer_bonus', $program->referral_config);
    }

    /**
     * Test inactive program.
     */
    public function testInactiveProgram(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);

        $active = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $inactive = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->inactive()
            ->create();

        $this->assertTrue($active->is_active);
        $this->assertFalse($inactive->is_active);
    }
}
