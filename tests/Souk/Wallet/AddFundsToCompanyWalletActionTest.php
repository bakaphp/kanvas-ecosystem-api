<?php

declare(strict_types=1);

namespace Tests\Souk\Wallet;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Actions\AddFundsToCompanyWalletAction;
use Kanvas\Souk\Wallet\Enums\TransactionSourceEnum;
use Kanvas\Souk\Wallet\Transaction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class AddFundsToCompanyWalletActionTest extends TestCase
{
    public function testCreditsCompanyFromOrderMetadataWhenFlagEnabled(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);

        $wrongCompany = $user->getCurrentCompany();

        $correctCompany = Companies::factory()->create(['users_id' => $user->getId()]);
        $correctCompany->associateApp($app);
        $correctCompany->associateUserApp($user, $app, 1);

        $this->assertNotEquals($wrongCompany->getId(), $correctCompany->getId());

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($wrongCompany->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($wrongCompany->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'metadata' => ['user_company_id' => $correctCompany->getId()],
                'reference' => 'wallet-regression',
            ]);

        $transaction = new AddFundsToCompanyWalletAction(
            order: $order,
            amount: 100.0,
            source: TransactionSourceEnum::RECHARGE_MANUAL,
            resolveCompanyFromMetadata: true,
        )->execute();

        $wallet = $transaction->wallet;

        $this->assertSame(Companies::class, $wallet->holder_type);
        $this->assertSame($correctCompany->getId(), (int) $wallet->holder_id);
        $this->assertNotSame($wrongCompany->getId(), (int) $wallet->holder_id);
        $this->assertEquals(100.0, (float) $transaction->amountFloat);
    }

    public function testCreditsCompanyWhenAdminPlacesOrderForACompanyTheyDoNotBelongTo(): void
    {
        /** @var Users $admin */
        $admin = auth()->user();
        $app = app(Apps::class);

        $owner = Users::factory()->create();

        $correctCompany = Companies::factory()->create(['users_id' => $owner->getId()]);
        $correctCompany->associateApp($app);
        $correctCompany->associateUserApp($owner, $app, 1);

        $this->assertNotSame(
            $admin->getId(),
            (int) $correctCompany->users_id,
            'Admin must NOT be the owner/member of the target company for this regression to be meaningful.'
        );

        $people = People::factory()
            ->withUserId($admin->getId())
            ->withAppId($app->getId())
            ->withCompanyId($admin->getCurrentCompany()->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($admin->getCurrentCompany()->getId())
            ->withUserId($admin->getId())
            ->withPeopleId($people->getId())
            ->create([
                'metadata' => ['user_company_id' => $correctCompany->getId()],
                'reference' => 'wallet-admin-on-behalf',
            ]);

        $transaction = new AddFundsToCompanyWalletAction(
            order: $order,
            amount: 75.0,
            source: TransactionSourceEnum::RECHARGE_MANUAL,
            resolveCompanyFromMetadata: true,
        )->execute();

        $wallet = $transaction->wallet;

        $this->assertSame(Companies::class, $wallet->holder_type);
        $this->assertSame($correctCompany->getId(), (int) $wallet->holder_id);
        $this->assertEquals(75.0, (float) $transaction->amountFloat);
        $this->assertSame($admin->getId(), (int) $transaction->meta['actor_user_id']);
    }

    public function testIdempotentReRunReturnsExistingTransactionWithoutDoubleDeposit(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'metadata' => ['user_company_id' => $company->getId()],
                'reference' => 'wallet-idempotency',
            ]);

        $first = new AddFundsToCompanyWalletAction(
            order: $order,
            amount: 50.0,
            source: TransactionSourceEnum::RECHARGE_MANUAL,
            resolveCompanyFromMetadata: true,
        )->execute();

        $balanceAfterFirst = (float) $first->wallet->refresh()->balanceFloat;

        $second = new AddFundsToCompanyWalletAction(
            order: $order,
            amount: 50.0,
            source: TransactionSourceEnum::RECHARGE_MANUAL,
            resolveCompanyFromMetadata: true,
        )->execute();

        $this->assertSame($first->getKey(), $second->getKey());

        $transactionCount = Transaction::query()
            ->where('wallet_id', $first->wallet->getKey())
            ->where('meta->order_id', $order->getId())
            ->count();

        $this->assertSame(1, $transactionCount);
        $this->assertSame($balanceAfterFirst, (float) $first->wallet->refresh()->balanceFloat);
    }
}
