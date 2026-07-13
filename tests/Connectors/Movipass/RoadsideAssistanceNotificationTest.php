<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Notifications\PendingOrderAssignmentNotification;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Notifications\Channels\KanvasDatabase;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use NotificationChannels\Expo\ExpoChannel;
use Tests\TestCase;

final class RoadsideAssistanceNotificationTest extends TestCase
{
    protected Apps $apps;
    protected Users $authUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->authUser = auth()->user();
    }

    public function testStatusNotificationShipsOnPushExpoAndDatabase(): void
    {
        $order = $this->createRoadsideOrder();

        $notification = new RoadsideAssistanceStatusNotification(
            $order,
            'Mechanic on site',
            'The mechanic has arrived at your location.',
            'on_site',
        );

        $this->assertSame(['push', 'database', 'expo'], $notification->channels());

        $resolved = $notification->via($this->authUser);
        $this->assertContains(OneSignalNotificationChannel::class, $resolved);
        $this->assertContains(ExpoChannel::class, $resolved);
        $this->assertContains(KanvasDatabase::class, $resolved);
    }

    public function testStatusNotificationBuildsExpoMessageWithoutStoredTemplate(): void
    {
        $order = $this->createRoadsideOrder();

        $notification = new RoadsideAssistanceStatusNotification(
            $order,
            'Mechanic on site',
            'The mechanic has arrived at your location.',
            'on_site',
        );

        // Regression: push_template is null, so without a getPushTemplate() override the
        // Expo channel would try to render a missing DB template and throw, dropping the push.
        $expo = $notification->toExpo($this->authUser)->toArray();

        $this->assertSame('Mechanic on site', $expo['title']);
        $this->assertSame('The mechanic has arrived at your location.', $expo['body']);
    }

    public function testStatusNotificationStillBuildsOneSignalPayload(): void
    {
        $order = $this->createRoadsideOrder();

        $notification = new RoadsideAssistanceStatusNotification(
            $order,
            'Mechanic on site',
            'The mechanic has arrived at your location.',
            'on_site',
        );

        $oneSignal = $notification->toOneSignal($this->authUser);

        $this->assertSame('Mechanic on site', $oneSignal['title']);
        $this->assertSame('The mechanic has arrived at your location.', $oneSignal['message']);
        $this->assertSame($this->authUser->getId(), $oneSignal['user_id']);
    }

    public function testPendingAssignmentNotificationShipsOnExpo(): void
    {
        $order = $this->createRoadsideOrder();

        $notification = new PendingOrderAssignmentNotification($order);

        $this->assertSame(['push', 'database', 'expo'], $notification->channels());

        $expo = $notification->toExpo($this->authUser)->toArray();
        $this->assertNotSame('', $expo['title']);
        $this->assertNotSame('', $expo['body']);
    }

    private function createRoadsideOrder(): Order
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => OrderTypeEnum::ROADSIDE_ASSISTANCE->value,
            'apps_id' => $this->apps->getId(),
        ]);

        $case = [
            'service' => 'Cambio de neumático',
            'location' => [
                'lat' => 18.486,
                'lng' => -69.931,
                'address' => 'Test Address, Santo Domingo',
            ],
        ];

        return Order::factory()
            ->withCompanyId($this->authUser->getCurrentCompany()->getId())
            ->withUserId($this->authUser->getId())
            ->create([
                'order_types_id' => $orderType->getId(),
                'user_email' => 'cliente@movipass.test',
                'user_phone' => '8090000000',
                'metadata' => ['assistance_case' => $case, 'data' => ['assistance_case' => $case]],
            ]);
    }
}
