<?php

declare(strict_types=1);

namespace Tests\Souk\Wallet;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Notifications\WalletRechargeConfirmedNotification;
use Kanvas\Souk\Wallet\Notifications\WalletRefundConfirmedNotification;
use Kanvas\Souk\Wallet\Transaction;
use Tests\TestCase;

final class WalletNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    private function makeStubTransaction(): Transaction
    {
        $transaction = new Transaction();
        $transaction->exists = true;
        $transaction->amount = 5000;

        return $transaction;
    }

    private function makeStubOrder(): Order
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();

        $order = new Order();
        $order->apps_id = $app->getId();
        $order->companies_id = $company->getId();
        $order->users_id = $user->getId();
        $order->order_number = fake()->unique()->numberBetween(1000000, 9999999);
        $order->total_gross_amount = 50.00;
        $order->total_net_amount = 50.00;
        $order->currency = 'USD';
        $order->status = 'completed';
        $order->exists = true;

        $order->setRelation('app', $app);
        $order->setRelation('company', $company);

        return $order;
    }

    public function testWalletRechargeConfirmedNotificationChannels(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $notification = new WalletRechargeConfirmedNotification(
            order: $this->makeStubOrder(),
            transaction: $this->makeStubTransaction(),
            app: $app,
            company: $company,
        );

        $channels = $notification->channels();

        $this->assertContains('mail', $channels);
        $this->assertContains('push', $channels);
    }

    public function testWalletRechargeConfirmedNotificationChannelsResolveToClassNames(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $notification = new WalletRechargeConfirmedNotification(
            order: $this->makeStubOrder(),
            transaction: $this->makeStubTransaction(),
            app: $app,
            company: $company,
        );

        $resolved = array_map(
            fn (string $slug): string => NotificationChannelEnum::getNotificationChannelBySlug($slug),
            $notification->channels(),
        );

        $this->assertContains('mail', $resolved);
        $this->assertContains(OneSignalNotificationChannel::class, $resolved);
    }

    public function testWalletRechargeConfirmedNotificationTemplateName(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $notification = new WalletRechargeConfirmedNotification(
            order: $this->makeStubOrder(),
            transaction: $this->makeStubTransaction(),
            app: $app,
            company: $company,
        );

        $this->assertSame('wallet-recharge-confirmed', $notification->getTemplateName());
    }

    public function testWalletRefundConfirmedNotificationChannels(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $notification = new WalletRefundConfirmedNotification(
            order: $this->makeStubOrder(),
            transaction: $this->makeStubTransaction(),
            app: $app,
            company: $company,
        );

        $channels = $notification->channels();

        $this->assertContains('mail', $channels);
        $this->assertContains('push', $channels);
    }

    public function testWalletRefundConfirmedNotificationTemplateName(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        $notification = new WalletRefundConfirmedNotification(
            order: $this->makeStubOrder(),
            transaction: $this->makeStubTransaction(),
            app: $app,
            company: $company,
        );

        $this->assertSame('wallet-refund-confirmed', $notification->getTemplateName());
    }
}
