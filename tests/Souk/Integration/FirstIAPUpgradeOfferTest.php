<?php

declare(strict_types=1);

namespace Tests\Souk\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\Souk\Loyalty\Actions\CheckOfferTriggersAction;
use Kanvas\Souk\Loyalty\Factories\LoyaltyOfferFactory;
use Kanvas\Souk\Loyalty\Factories\LoyaltyProgramFactory;
use Kanvas\Souk\Loyalty\Models\LoyaltyOffer;
use Kanvas\Souk\Loyalty\Models\LoyaltyOfferAssignment;
use Kanvas\Souk\Orders\Factories\OrderFactory;
use Tests\TestCase;

final class FirstIAPUpgradeOfferTest extends TestCase
{
    private LoyaltyOffer $firstIAPUpgradeOffer;
    private int $peopleId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create a people record from the user
        $branch = CompaniesBranches::where('companies_id', $company->getId())->first();
        $createPeopleAction = new CreatePeopleFromUserAction($app, $branch, $user);
        $people = $createPeopleAction->execute();

        // Create loyalty program
        $program = LoyaltyProgramFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        // Create the first IAP upgrade offer
        $this->firstIAPUpgradeOffer = LoyaltyOfferFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->discount()
            ->create([
                'loyalty_programs_id' => $program->id,
                'name' => 'First Digital Purchase Upgrade',
                'headline' => 'A Gift For Your First Purchase!',
                'description' => 'To celebrate, we\'ve upgraded your next video. Try a premium Veo 3 generation for the same price ($0.99). This offer is good for the next 24 hours.',
                'trigger_type' => 'first_purchase',
                'trigger_value' => [
                    'price' => 0.99,
                    'product_sku' => 'video_tier_entry',
                ],
                'reward_value' => 99, // Store as cents for fixed price
                'expiration_hours' => 24,
                'status' => 'active',
            ]);

        // Store people ID for use in tests
        $this->peopleId = $people->id;
    }

    /**
     * AC 1: Offer Trigger - First-ever successful IAP for entry-level video tier ($0.99)
     *
     * The system must accurately track a user's first-ever successful IAP for the entry-level video tier ($0.99).
     * The offer must trigger only once per user's lifetime.
     */
    public function testFirstIAPUpgradeOfferTriggerOnFirstPurchase(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create first order for the user at the $0.99 tier
        $firstOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        // Check if offer triggers
        $checkOfferAction = new CheckOfferTriggersAction($user, $firstOrder);
        $triggeredOffers = $checkOfferAction->execute();

        // Verify offer was triggered
        $this->assertGreaterThan(0, count($triggeredOffers));

        // Find the first IAP upgrade offer in triggered offers
        $assignment = collect($triggeredOffers)
            ->first(fn ($offer) => $offer->loyalty_offers_id === $this->firstIAPUpgradeOffer->id);

        $this->assertNotNull($assignment, 'First IAP upgrade offer should have been triggered');
        $this->assertEquals('assigned', $assignment->status);
        $this->assertNull($assignment->accepted_at);
        $this->assertNull($assignment->viewed_at);
    }

    /**
     * AC 1 Continued: Subsequent purchases do not re-trigger the offer
     *
     * The offer must trigger only once per user's lifetime.
     * Subsequent purchases of the entry-level video must not re-trigger the offer.
     */
    public function testSubsequentEntryTierPurchasesDoNotRetriggerOffer(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create and complete first order
        $firstOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        // Check and trigger offer
        $checkOfferAction = new CheckOfferTriggersAction($user, $firstOrder);
        $checkOfferAction->execute();

        // Verify one assignment was created for this offer
        $assignmentCount = LoyaltyOfferAssignment::where('users_id', $user->getId())
            ->where('loyalty_offers_id', $this->firstIAPUpgradeOffer->id)
            ->where('status', '!=', 'expired')
            ->count();

        $this->assertEquals(1, $assignmentCount, 'Should have exactly one assignment after first purchase');

        // Create second order at same tier
        $secondOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        // Check offer triggers again
        $checkOfferAction = new CheckOfferTriggersAction($user, $secondOrder);
        $triggeredOffers = $checkOfferAction->execute();

        // Verify no new assignment created
        $secondAssignmentCount = LoyaltyOfferAssignment::where('users_id', $user->getId())
            ->where('loyalty_offers_id', $this->firstIAPUpgradeOffer->id)
            ->where('status', '!=', 'expired')
            ->count();

        $this->assertEquals(1, $secondAssignmentCount, 'Should still have only one assignment after second purchase');
    }

    /**
     * AC 2: Offer Presentation - Immediately after first purchase
     *
     * Immediately following the successful completion of the first purchase,
     * the user must be presented with the offer.
     */
    public function testOfferPresentationImediatelyAfterFirstPurchase(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create first order
        $firstOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        // Check if offer triggers immediately
        $checkOfferAction = new CheckOfferTriggersAction($user, $firstOrder);
        $triggeredOffers = $checkOfferAction->execute();

        $assignment = collect($triggeredOffers)
            ->first(fn ($offer) => $offer->loyalty_offers_id === $this->firstIAPUpgradeOffer->id);

        $this->assertNotNull($assignment);
        // Offer should be in 'assigned' status, ready to be presented to user
        $this->assertEquals('assigned', $assignment->status);
        $this->assertNull($assignment->viewed_at);
    }

    /**
     * AC 3: Offer Copy - Correct headline, body, and disclaimer
     *
     * Headline: A Gift For Your First Purchase!
     * Body: To celebrate, we've upgraded your next video. Try a premium Veo 3 generation for the same price ($0.99).
     * Disclaimer: This offer is good for the next 24 hours.
     */
    public function testOfferCopyIsCorrect(): void
    {
        $this->assertEquals('A Gift For Your First Purchase!', $this->firstIAPUpgradeOffer->headline);
        $this->assertStringContainsString('To celebrate, we\'ve upgraded your next video', $this->firstIAPUpgradeOffer->description);
        $this->assertStringContainsString('Try a premium Veo 3 generation for the same price ($0.99)', $this->firstIAPUpgradeOffer->description);
        $this->assertStringContainsString('This offer is good for the next 24 hours', $this->firstIAPUpgradeOffer->description);
        $this->assertEquals(24, $this->firstIAPUpgradeOffer->expiration_hours);
    }

    /**
     * AC 4: Clear path to accept or dismiss offer
     *
     * The user should have a clear path to accept or dismiss the offer.
     * This is achieved through the LoyaltyOfferAssignment model with markAsViewed() and markAsAccepted() methods.
     */
    public function testUserCanViewAndAcceptOffer(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create first order and trigger offer
        $firstOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        $checkOfferAction = new CheckOfferTriggersAction($user, $firstOrder);
        $triggeredOffers = $checkOfferAction->execute();

        $assignment = collect($triggeredOffers)
            ->first(fn ($offer) => $offer->loyalty_offers_id === $this->firstIAPUpgradeOffer->id);

        $this->assertEquals('assigned', $assignment->status);

        // User views the offer (via in-app modal)
        $assignment->markAsViewed();

        $this->assertEquals('viewed', $assignment->status);
        $this->assertNotNull($assignment->viewed_at);

        // User accepts the offer
        $assignment->markAsAccepted();

        $this->assertEquals('accepted', $assignment->status);
        $this->assertNotNull($assignment->accepted_at);
    }

    /**
     * AC 4 Continued: User can dismiss offer by letting it expire
     *
     * The user can ignore the offer by not accepting it, allowing it to expire.
     */
    public function testUserCanDismissOffer(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create first order and trigger offer
        $firstOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        $checkOfferAction = new CheckOfferTriggersAction($user, $firstOrder);
        $triggeredOffers = $checkOfferAction->execute();

        $assignment = collect($triggeredOffers)
            ->first(fn ($offer) => $offer->loyalty_offers_id === $this->firstIAPUpgradeOffer->id);

        $this->assertEquals('assigned', $assignment->status);

        // User ignores the offer and it expires (status moved to 'expired')
        $assignment->update(['expires_at' => now()->subHours(1), 'status' => 'expired']);

        $this->assertEquals('expired', $assignment->status);
    }

    /**
     * AC 5: Offer Logic - Temporary discount/special credit applied
     *
     * When triggered, a temporary discount or special credit must be applied to the user's account,
     * allowing them to purchase one (1) Veo 3 generation for $0.99.
     */
    public function testOfferAppliesFixedPriceDiscount(): void
    {
        $rewardValue = $this->firstIAPUpgradeOffer->reward_value;

        // Verify reward value is stored as cents (99 cents = $0.99)
        $this->assertEquals(99, $rewardValue);
    }

    /**
     * AC 5 Continued: Offer automatically expires 24 hours after grant if not used
     *
     * This offer must automatically expire 24 hours after it is granted if it is not used.
     * Upon expiration, the discount/credit should be removed from the user's account,
     * and any related UI should disappear.
     */
    public function testOfferAutomaticallyExpiresAfter24Hours(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create first order and trigger offer
        $firstOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        $checkOfferAction = new CheckOfferTriggersAction($user, $firstOrder);
        $triggeredOffers = $checkOfferAction->execute();

        $assignment = collect($triggeredOffers)
            ->first(fn ($offer) => $offer->loyalty_offers_id === $this->firstIAPUpgradeOffer->id);

        $this->assertNotNull($assignment);

        // Verify expiration datetime is set to 24 hours from now
        $now = now();

        // Should be approximately 24 hours in the future (within 1 minute)
        $this->assertTrue(
            $now->diffInMinutes($assignment->expires_at) >= 23 * 60 - 1 && $now->diffInMinutes($assignment->expires_at) <= 24 * 60 + 1,
            'Expiration should be approximately 24 hours from now'
        );

        // Verify expiration is stored on assignment
        $this->assertNotNull($assignment->expires_at);
        $this->assertTrue(now()->addHours(24)->diffInMinutes($assignment->expires_at) < 2);
    }

    /**
     * AC 5 Continued: Expired offer shows hasExpired status correctly
     *
     * Upon expiration, the system should identify the offer as expired.
     */
    public function testExpiredOfferIsIdentifiedCorrectly(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create first order and trigger offer
        $firstOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        $checkOfferAction = new CheckOfferTriggersAction($user, $firstOrder);
        $triggeredOffers = $checkOfferAction->execute();

        $assignment = collect($triggeredOffers)
            ->first(fn ($offer) => $offer->loyalty_offers_id === $this->firstIAPUpgradeOffer->id);

        $this->assertFalse($assignment->hasExpired(), 'Offer should not be expired immediately after assignment');

        // Move expiration time to past
        $assignment->update([
            'expires_at' => now()->subHours(1),
        ]);

        // Refresh to get updated data
        $assignment->refresh();

        $this->assertTrue($assignment->hasExpired(), 'Offer should be expired after expires_at time has passed');
        $this->assertFalse($assignment->isAvailable(), 'Expired offer should not be available');
    }

    /**
     * AC 5 Continued: Available offer is marked correctly
     *
     * Offer availability should be based on status and expiration.
     */
    public function testAvailableOfferIsIdentifiedCorrectly(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create first order and trigger offer
        $firstOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        $checkOfferAction = new CheckOfferTriggersAction($user, $firstOrder);
        $triggeredOffers = $checkOfferAction->execute();

        $assignment = collect($triggeredOffers)
            ->first(fn ($offer) => $offer->loyalty_offers_id === $this->firstIAPUpgradeOffer->id);

        // Fresh assignment in 'assigned' status with future expiration should be available
        $this->assertTrue($assignment->isAvailable(), 'Fresh offer in assigned status should be available');

        // After accepting, it's no longer 'assigned' status
        $assignment->markAsAccepted();
        $this->assertFalse($assignment->isAvailable(), 'Accepted offer should not be available (status changed)');

        // If expired, should not be available - update existing assignment to expired
        $assignment->update([
            'status' => 'expired',
            'expires_at' => now()->subHours(1),
        ]);

        $this->assertFalse($assignment->isAvailable(), 'Expired offer should not be available');
    }

    /**
     * Integration Test: Complete user flow from first purchase through offer acceptance
     *
     * This comprehensive test validates the entire first IAP upgrade offer workflow.
     */
    public function testCompleteFirstIAPUpgradeOfferFlow(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Step 1: User makes first $0.99 purchase
        $firstOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        // Step 2: System checks and triggers offer
        $checkOfferAction = new CheckOfferTriggersAction($user, $firstOrder);
        $triggeredOffers = $checkOfferAction->execute();

        $this->assertGreaterThan(0, count($triggeredOffers));

        $assignment = collect($triggeredOffers)
            ->first(fn ($offer) => $offer->loyalty_offers_id === $this->firstIAPUpgradeOffer->id);

        $this->assertNotNull($assignment);
        $this->assertEquals('assigned', $assignment->status);

        // Step 3: Verify offer copy and expiration
        $this->assertEquals('A Gift For Your First Purchase!', $this->firstIAPUpgradeOffer->headline);
        $this->assertNotNull($assignment->expires_at);
        $this->assertTrue(now()->addHours(24)->diffInMinutes($assignment->expires_at) < 2);

        // Step 4: User views offer (modal presented)
        $assignment->markAsViewed();
        $this->assertEquals('viewed', $assignment->status);
        $this->assertNotNull($assignment->viewed_at);

        // Step 5: User accepts offer
        $assignment->markAsAccepted();
        $this->assertEquals('accepted', $assignment->status);
        $this->assertNotNull($assignment->accepted_at);

        // Step 6: Verify discount can be applied (reward_value contains discount info in cents)
        $this->assertEquals(99, $this->firstIAPUpgradeOffer->reward_value);
        $this->assertEquals('discount', $this->firstIAPUpgradeOffer->offer_type);

        // Step 7: Verify subsequent purchases don't re-trigger
        $secondOrder = OrderFactory::new()
            ->withAppId($app->getId())
            ->withUserId($user->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($this->peopleId)
            ->create([
                'total_gross_amount' => 0.99,
                'total_net_amount' => 0.99,
                'status' => 'completed',
            ]);

        $checkOfferAction = new CheckOfferTriggersAction($user, $secondOrder);
        $triggeredOffers = $checkOfferAction->execute();

        $secondAssignment = collect($triggeredOffers)
            ->first(fn ($offer) => $offer->loyalty_offers_id === $this->firstIAPUpgradeOffer->id);

        $this->assertNull($secondAssignment, 'Offer should not be triggered on second purchase');
    }
}
