<?php

declare(strict_types=1);

namespace Tests\Souk\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountType;
use Kanvas\Souk\Discounts\Models\OrderDiscount;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Kanvas\Souk\Loyalty\Models\LoyaltyTier;
use Kanvas\Souk\Loyalty\Models\LoyaltyTierMembership;
use Kanvas\Souk\Orders\Factories\OrderFactory;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Referrals\Actions\GenerateReferralCodeAction;
use Kanvas\Souk\Referrals\Actions\ProcessReferralCodeRedemptionAction;
use Kanvas\Souk\Referrals\Factories\ReferralRedemptionFactory;
use Kanvas\Souk\Referrals\Models\OrderReferralCode;
use Kanvas\Souk\Referrals\Models\ReferralCode;
use Kanvas\Souk\Referrals\Models\ReferralRedemption;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ReferralCodeRedemptionTest extends TestCase
{
    /**
     * Test processing referral code redemption when order uses referral discount.
     */
    public function testProcessReferralCodeRedemptionWhenOrderUsesReferralDiscount(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();
        $referee = Users::factory()->create();
        $app->associateUser(
            user: $referee,
            isActive: 1
        );

        // Create loyalty program and tier
        $loyaltyProgram = LoyaltyProgram::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        $loyaltyTier = LoyaltyTier::factory()->create([
            'companies_id' => $company->getId(),
            'loyalty_programs_id' => $loyaltyProgram->id,
            'level' => 1,
            'min_points' => 0,
        ]);

        // Create loyalty membership for referrer
        $referrerMembership = LoyaltyTierMembership::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $referrer->id,
            'loyalty_programs_id' => $loyaltyProgram->id,
            'loyalty_tiers_id' => $loyaltyTier->id,
            'current_points' => 100,
            'lifetime_points' => 100,
        ]);

        // Generate referral code
        $generateAction = new GenerateReferralCodeAction(
            $referrer,
            $loyaltyProgram,
            $app,
            500, // referrer reward
            100, // referee reward
            10.0 // referee discount
        );
        $referralCode = $generateAction->execute();

        // Create discount type and discount
        $discountType = DiscountType::factory()->create([
            'apps_id' => $app->getId(),
        ]);

        $discount = Discount::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'discount_type_id' => $discountType->id,
            'code' => $referralCode->code,
            'value' => 10.0,
            'is_percentage' => true,
            'is_active' => true,
        ]);

        // Update referral code to link to discount
        $referralCode->update(['discounts_id' => $discount->id]);

        $createPeopleAction = new CreatePeopleFromUserAction(
            $app,
            $company->defaultBranch,
            $referee,
        );

        // Create order
        $order = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referee->id)
            ->withPeopleId($createPeopleAction->execute()->getId())
            ->completed()
            ->create([
                'total_gross_amount' => 100.0,
            ]);

        // Create order discount (simulating that the referral code was used)
        OrderDiscount::create([
            'apps_id' => $app->getId(),
            'order_id' => $order->id,
            'discount_id' => $discount->id,
            'amount' => 10.0,
        ]);

        // Process referral redemption
        $action = new ProcessReferralCodeRedemptionAction($order, $referee);
        $results = $action->execute();

        // Assertions
        $this->assertCount(1, $results);

        $result = $results[0];
        $this->assertInstanceOf(ReferralRedemption::class, $result['redemption']);
        $this->assertInstanceOf(OrderReferralCode::class, $result['order_referral_code']);
        $this->assertInstanceOf(ReferralCode::class, $result['referral_code']);

        // Check that redemption was created correctly
        $redemption = $result['redemption'];
        $this->assertEquals($referralCode->id, $redemption->referral_codes_id);
        $this->assertEquals($referrer->id, $redemption->referrer_user_id);
        $this->assertEquals($referee->id, $redemption->referee_user_id);
        $this->assertEquals($order->id, $redemption->orders_id);
        $this->assertEquals(500, $redemption->referrer_points_awarded);
        $this->assertEquals(10.0, $redemption->referee_discount_amount);
        $this->assertEquals('completed', $redemption->status);

        // Check that order referral code was created correctly
        $orderReferralCode = $result['order_referral_code'];
        $this->assertEquals($order->id, $orderReferralCode->orders_id);
        $this->assertEquals($referralCode->id, $orderReferralCode->referral_codes_id);
        $this->assertEquals($referrer->id, $orderReferralCode->referrer_user_id);
        $this->assertEquals($referee->id, $orderReferralCode->referee_user_id);
        $this->assertEquals(500, $orderReferralCode->referrer_points_earned);
        $this->assertEquals(10.0, $orderReferralCode->referee_discount_applied);
        $this->assertEquals('completed', $orderReferralCode->status);

        // Check that referral code usage was incremented
        $referralCode->refresh();
        $this->assertEquals(1, $referralCode->current_uses);

        // Check that referrer received points
        $referrerMembership->refresh();
        $this->assertEquals(600, $referrerMembership->current_points); // 100 + 500
        $this->assertEquals(600, $referrerMembership->lifetime_points); // 100 + 500
    }

    /**
     * Test prevents self-referral.
     */
    public function testPreventsSelfreferral(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();

        // Create loyalty program
        $loyaltyProgram = LoyaltyProgram::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        // Generate referral code for user
        $generateAction = new GenerateReferralCodeAction(
            $referrer,
            $loyaltyProgram,
            $app
        );
        $referralCode = $generateAction->execute();

        // Create discount
        $discountType = DiscountType::factory()->create([
            'apps_id' => $app->getId(),
        ]);

        $discount = Discount::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'discount_type_id' => $discountType->id,
            'code' => $referralCode->code,
            'value' => 10.0,
            'is_percentage' => true,
            'is_active' => true,
        ]);

        $referralCode->update(['discounts_id' => $discount->id]);

        // Create order for same user (self-referral attempt)
        $order = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referrer->id)
            ->withPeopleId(new CreatePeopleFromUserAction(
                $app,
                $company->defaultBranch,
                $referrer,
            )->execute()->getId())
            ->completed()
            ->create([
                'total_gross_amount' => 100.0,
            ]);

        OrderDiscount::create([
            'apps_id' => $app->getId(),
            'order_id' => $order->id,
            'discount_id' => $discount->id,
            'amount' => 10.0,
        ]);

        // Process referral redemption
        $action = new ProcessReferralCodeRedemptionAction($order, $referrer);
        $results = $action->execute();

        // Should return empty results (no self-referral allowed)
        $this->assertCount(0, $results);

        // Verify no redemption records were created for this referral code
        $this->assertEquals(0, ReferralRedemption::where('referral_codes_id', $referralCode->id)->count());
        $this->assertEquals(0, OrderReferralCode::where('referral_codes_id', $referralCode->id)->count());

        // Verify referral code usage was not incremented
        $referralCode->refresh();
        $this->assertEquals(0, $referralCode->current_uses);
    }

    /**
     * Test prevents duplicate referral redemption.
     */
    public function testPreventsDuplicateReferralRedemption(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $app = app(Apps::class);
        $referrer = auth()->user();
        $referee = Users::factory()->create();
        $app->associateUser(
            user: $referee,
            isActive: 1
        );

        // Create loyalty program
        $loyaltyProgram = LoyaltyProgram::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
        ]);

        // Generate referral code
        $generateAction = new GenerateReferralCodeAction(
            $referrer,
            $loyaltyProgram,
            $app
        );
        $referralCode = $generateAction->execute();

        // Create existing redemption
        ReferralRedemptionFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->completed()
            ->create([
                'referral_codes_id' => $referralCode->id,
                'referrer_user_id' => $referrer->id,
                'referee_user_id' => $referee->id,
            ]);

        // Create new order with same referral code
        $discountType = DiscountType::factory()->create([
            'apps_id' => $app->getId(),
        ]);

        $discount = Discount::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'discount_type_id' => $discountType->id,
            'code' => $referralCode->code,
            'value' => 10.0,
            'is_percentage' => true,
            'is_active' => true,
        ]);

        $referralCode->update(['discounts_id' => $discount->id]);

        $order = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($referee->id)
            ->withPeopleId(new CreatePeopleFromUserAction(
                $app,
                $company->defaultBranch,
                $referee,
            )->execute()->getId())
            ->completed()
            ->create([
                'total_gross_amount' => 100.0,
            ]);

        OrderDiscount::create([
            'apps_id' => $app->getId(),
            'order_id' => $order->id,
            'discount_id' => $discount->id,
            'amount' => 10.0,
        ]);

        // Process referral redemption
        $action = new ProcessReferralCodeRedemptionAction($order, $referee);
        $results = $action->execute();

        // Should return empty results (duplicate redemption prevented)
        $this->assertCount(0, $results);

        // Verify only one redemption record exists for this referral code
        $this->assertEquals(1, ReferralRedemption::where('referral_codes_id', $referralCode->id)->count());
    }
}
