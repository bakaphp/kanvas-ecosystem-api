<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Mailgun;

use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum as CommunicationDeliveryStatusEnum;
use Kanvas\Connectors\Mailgun\Enums\DeliveryStatusEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCaseUnit;

final class DeliveryStatusEnumTest extends TestCaseUnit
{
    #[DataProvider('events')]
    public function testMapsMailgunEvents(string $event, string $severity, ?DeliveryStatusEnum $expected): void
    {
        $this->assertSame($expected, DeliveryStatusEnum::fromEvent($event, $severity));
    }

    public static function events(): array
    {
        return [
            'accepted' => ['accepted', '', DeliveryStatusEnum::ACCEPTED],
            'delivered' => ['Delivered', '', DeliveryStatusEnum::DELIVERED],
            'temporary failure' => ['failed', 'temporary', DeliveryStatusEnum::TEMPORARY_FAILURE],
            'permanent failure' => ['failed', 'permanent', DeliveryStatusEnum::FAILED],
            'complaint' => ['complained', '', DeliveryStatusEnum::COMPLAINED],
            'unsubscribe' => ['unsubscribed', '', DeliveryStatusEnum::UNSUBSCRIBED],
            'open is not a delivery event' => ['opened', '', null],
        ];
    }

    #[DataProvider('deliveryStatuses')]
    public function testMapsOntoTheCommunicationDeliveryStatus(DeliveryStatusEnum $event, CommunicationDeliveryStatusEnum $expected): void
    {
        $this->assertSame($expected, $event->toDeliveryStatus());
    }

    public static function deliveryStatuses(): array
    {
        return [
            'accepted' => [DeliveryStatusEnum::ACCEPTED, CommunicationDeliveryStatusEnum::ACCEPTED],
            'a temporary failure is still being retried' => [DeliveryStatusEnum::TEMPORARY_FAILURE, CommunicationDeliveryStatusEnum::ACCEPTED],
            'delivered' => [DeliveryStatusEnum::DELIVERED, CommunicationDeliveryStatusEnum::DELIVERED],
            'permanent failure' => [DeliveryStatusEnum::FAILED, CommunicationDeliveryStatusEnum::FAILED],
            'a complaint proves delivery' => [DeliveryStatusEnum::COMPLAINED, CommunicationDeliveryStatusEnum::DELIVERED],
            'an unsubscribe proves delivery' => [DeliveryStatusEnum::UNSUBSCRIBED, CommunicationDeliveryStatusEnum::DELIVERED],
        ];
    }

    public function testOnlyComplaintsAndUnsubscribesAreStopRequests(): void
    {
        $stops = array_values(array_filter(
            DeliveryStatusEnum::cases(),
            static fn (DeliveryStatusEnum $event): bool => $event->isStopRequest(),
        ));

        $this->assertSame([DeliveryStatusEnum::COMPLAINED, DeliveryStatusEnum::UNSUBSCRIBED], $stops);
    }
}
