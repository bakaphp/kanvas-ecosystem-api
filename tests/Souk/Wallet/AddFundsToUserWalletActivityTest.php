<?php

declare(strict_types=1);

namespace Tests\Souk\Wallet;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemData;
use Kanvas\Souk\Orders\Jobs\GenerateOrderReceiptPdfJob;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Activities\AddFundsToUserWalletActivity;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum;
use Kanvas\Souk\Wallet\Notifications\WalletRechargeConfirmedNotification;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class AddFundsToUserWalletActivityTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $this->setIntegration(
            $app,
            IntegrationsEnum::INTERNAL,
            InternalHandler::class,
            $company,
            $user,
        );
    }

    private function makeActivity(): AddFundsToUserWalletActivity
    {
        return new AddFundsToUserWalletActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            [],
        );
    }

    private function makeWalletCoinOrder(bool $withUser = true): Order
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $product = Products::factory()
            ->state([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => $user->getId(),
            ])
            ->create();

        $variant = Variants::factory()
            ->withProductId($product->getId())
            ->create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => $user->getId(),
            ]);

        $variant->addAttribute(ConfigurationEnum::PRODUCT_TYPE_USER_WALLET_COIN_SLUG->value, 'true');
        $variant->addAttribute('wallet-coin-amount', '50');

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'users_id' => $withUser ? $user->getId() : 0,
                'people_id' => $people->getId(),
                'total_gross_amount' => 50.00,
                'total_net_amount' => 50.00,
                'status' => 'completed',
                'payment_status' => 'paid',
            ]);

        $currency = Currencies::where('code', 'USD')->first()
            ?? Currencies::first();

        $order->addItem(new OrderItemData(
            app: $app,
            variant: $variant->fresh(),
            name: $variant->name,
            sku: $variant->sku,
            quantity: 1,
            price: 50.00,
            tax: 0.0,
            discount: 0.0,
            currency: $currency,
        ));

        return $order->fresh();
    }

    private function makeOrderWithoutWalletCoinItem(): Order
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $product = Products::factory()
            ->state([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => $user->getId(),
            ])
            ->create();

        $variant = Variants::factory()
            ->withProductId($product->getId())
            ->create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'users_id' => $user->getId(),
            ]);

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create([
                'people_id' => $people->getId(),
                'total_gross_amount' => 50.00,
                'total_net_amount' => 50.00,
            ]);

        $currency = Currencies::where('code', 'USD')->first()
            ?? Currencies::first();

        $order->addItem(new OrderItemData(
            app: $app,
            variant: $variant->fresh(),
            name: $variant->name,
            sku: $variant->sku,
            quantity: 1,
            price: 50.00,
            tax: 0.0,
            discount: 0.0,
            currency: $currency,
        ));

        return $order->fresh();
    }

    public function testHappyPathDepositsFundsAndNotifiesUser(): void
    {
        Bus::fake();
        Notification::fake();

        $app = app(Apps::class);
        $user = static::$cachedUser;

        $order = $this->makeWalletCoinOrder(withUser: true);
        $wallet = $user->createAppWallet($app, ['name' => 'default']);
        $balanceBefore = (float) $wallet->balanceFloat;

        $result = $this->makeActivity()->execute($order, $app, []);

        $this->assertTrue($result['result']);
        $this->assertSame('Funds added to user wallet successfully.', $result['message']);

        $wallet->refresh();
        $this->assertGreaterThan(
            $balanceBefore,
            (float) $wallet->balanceFloat,
            'User wallet balance should have increased after activity',
        );

        Bus::assertDispatched(GenerateOrderReceiptPdfJob::class, function (GenerateOrderReceiptPdfJob $job) use ($order): bool {
            return $job->entity->id === $order->id
                && $job->html === 'wallet-recharge-receipt';
        });

        Notification::assertSentTo($order->user, WalletRechargeConfirmedNotification::class);
    }

    public function testShortCircuitWhenNoWalletCoinItem(): void
    {
        Bus::fake();
        Notification::fake();

        $app = app(Apps::class);
        $order = $this->makeOrderWithoutWalletCoinItem();

        $result = $this->makeActivity()->execute($order, $app, []);

        $this->assertFalse($result['result']);
        $this->assertStringContainsString('No wallet fund transaction found', $result['message']);

        Bus::assertNotDispatched(GenerateOrderReceiptPdfJob::class);
        Notification::assertNothingSent();
    }

    public function testShortCircuitWhenOrderHasNoUser(): void
    {
        Bus::fake();
        Notification::fake();

        $app = app(Apps::class);
        $order = $this->makeWalletCoinOrder(withUser: false);

        $result = $this->makeActivity()->execute($order, $app, []);

        $this->assertFalse($result['result']);
        $this->assertStringContainsString('User not found in order', $result['message']);

        Bus::assertNotDispatched(GenerateOrderReceiptPdfJob::class);
        Notification::assertNothingSent();
    }
}
