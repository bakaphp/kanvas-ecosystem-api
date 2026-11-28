<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Notifications\Actions\MuteAllNotificationAction;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Kanvas\Notifications\Mailings\Services\AppUserMailingEligibilityService;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Models\UsersNotificationsSettings;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

trait MakesActiveUsers
{
    protected function makeOrderType(Companies $company): OrderTypes
    {
        $type = new OrderTypes();
        $type->apps_id = app(Apps::class)->getId();
        $type->companies_id = $company->getId();
        $type->name = 'mailing_' . uniqid();
        $type->saveOrFail();

        return $type;
    }

    protected function makeUserWhoPaid(OrderTypes $type, array $attributes = []): Users
    {
        $payer = Users::factory()->create(['email' => 'payer-' . uniqid() . '@example.test', ...$attributes]);
        $this->payFor($type, $payer);

        return $payer;
    }

    protected function makeUserWithoutPaidPayment(OrderTypes $type): Users
    {
        $user = Users::factory()->create(['email' => 'unpaid-' . uniqid() . '@example.test']);
        $this->payFor($type, $user, status: 'failed');

        return $user;
    }

    protected function payFor(OrderTypes $type, Users $payer, string $status = 'paid'): void
    {
        $creator = auth()->user();
        $people = People::factory()
            ->withAppId($type->apps_id)
            ->withCompanyId($type->companies_id)
            ->withUserId($creator->getId())
            ->create();
        $order = Order::factory()
            ->withAppId($type->apps_id)
            ->withCompanyId($type->companies_id)
            ->withUserId($creator->getId())
            ->withPeopleId($people->getId())
            ->create([
                'order_types_id' => $type->getId(),
                'status' => 'completed',
                'payment_status' => 'paid',
            ]);

        $payment = new Payments();
        $payment->apps_id = $type->apps_id;
        $payment->companies_id = $type->companies_id;
        $payment->users_id = $payer->getId();
        $payment->payment_methods_id = 1;
        $payment->payable_id = $order->getId();
        $payment->payable_type = Order::class;
        $payment->payment_date = '2026-02-10';
        $payment->payment_method = 'card';
        $payment->amount = 100.0;
        $payment->currency = 'USD';
        $payment->status = $status;
        $payment->is_deleted = false;
        $payment->created_at = '2026-02-10 15:00:00';
        $payment->saveOrFail();
    }

    protected function saveNotificationSetting(Users $user, string $typeName, bool $enabled, array $channels): void
    {
        $app = app(Apps::class);
        $type = $this->notificationType($app, $typeName);

        UsersNotificationsSettings::query()->create([
            'users_id' => $user->getId(),
            'apps_id' => $app->getId(),
            'notifications_types_id' => $type->getId(),
            'is_enabled' => $enabled ? 1 : 0,
            'channels' => $channels,
        ]);
    }

    protected function muteEverything(Users $user): void
    {
        $app = app(Apps::class);
        $this->notificationType($app, AppUserMailingEligibilityService::NOTIFICATION_TYPE);

        new MuteAllNotificationAction($user, $app)->execute();
    }

    protected function mailChannel(): array
    {
        return [NotificationChannelEnum::MAIL->value];
    }

    private function notificationType(Apps $app, string $name): NotificationTypes
    {
        return NotificationTypes::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'name' => $name,
                'is_deleted' => 0,
            ],
            [
                'key' => $name,
                'template' => $name,
                'system_modules_id' => SystemModulesRepository::getByModelName(Blank::class, $app)->getId(),
            ],
        );
    }
}
