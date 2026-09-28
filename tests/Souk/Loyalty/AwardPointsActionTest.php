<?php

declare(strict_types=1);

namespace Tests\Souk\Loyalty;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Loyalty\Actions\AwardPointsAction;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Kanvas\Souk\Loyalty\Models\LoyaltyTier;
use Kanvas\Souk\Loyalty\Models\LoyaltyTierMembership;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Transaction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class AwardPointsActionTest extends TestCase
{
    public function testZeroTotalOrderDoesNotAttemptWalletDeposit(): void
    {
        $walletHolder = $this->createWalletHolder();
        $membership = $this->createMembership($walletHolder, pointsPerDollar: 1.0);
        $order = $this->createOrder($walletHolder, total: 0.0);

        $orderLoyaltyPoints = new AwardPointsAction($order, $membership)->execute();

        $this->assertEquals(0.0, (float) $orderLoyaltyPoints->points_earned);
        $this->assertSame(
            0,
            Transaction::query()->where('meta->order_id', $order->getId())->count()
        );
    }

    public function testOrderWithPointsStillDepositsToWallet(): void
    {
        $walletHolder = $this->createWalletHolder();
        $membership = $this->createMembership($walletHolder, pointsPerDollar: 1.0);
        $order = $this->createOrder($walletHolder, total: 100.0);

        $orderLoyaltyPoints = new AwardPointsAction($order, $membership)->execute();

        $this->assertGreaterThan(0, (float) $orderLoyaltyPoints->points_earned);
        $this->assertSame(
            1,
            Transaction::query()->where('meta->order_id', $order->getId())->count()
        );
    }

    /**
     * The order's user owns the wallet being credited — keep it off the shared auth user so
     * deposits here don't shift the balance other Souk wallet tests assert on.
     */
    private function createWalletHolder(): Users
    {
        $app = app(Apps::class);
        $user = Users::factory()->create();
        $app->associateUser($user, 1);

        return $user;
    }

    private function createMembership(Users $user, float $pointsPerDollar): LoyaltyTierMembership
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $program = LoyaltyProgram::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'points_per_dollar' => $pointsPerDollar,
            'referral_config' => ['add_to_wallet' => true],
        ]);

        $tier = LoyaltyTier::factory()->bronze()->create([
            'companies_id' => $company->getId(),
            'loyalty_programs_id' => $program->getId(),
        ]);

        return LoyaltyTierMembership::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'loyalty_programs_id' => $program->getId(),
            'loyalty_tiers_id' => $tier->getId(),
            'current_points' => 0,
            'lifetime_points' => 0,
        ]);
    }

    private function createOrder(Users $user, float $total): Order
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'total_gross_amount' => $total,
            ]);
    }
}
