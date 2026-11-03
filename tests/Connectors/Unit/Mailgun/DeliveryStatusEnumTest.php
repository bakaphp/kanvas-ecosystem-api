<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Mailgun;

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

    public function testOnlyDeliveredAndPermanentFailureAreOutcomes(): void
    {
        $outcomes = array_values(array_filter(
            DeliveryStatusEnum::cases(),
            static fn (DeliveryStatusEnum $status): bool => $status->isDeliveryOutcome(),
        ));

        $this->assertSame([DeliveryStatusEnum::DELIVERED, DeliveryStatusEnum::FAILED], $outcomes);
    }

    public function testAStatusOnlyMovesForward(): void
    {
        $this->assertTrue(DeliveryStatusEnum::ACCEPTED->canFollow(null));
        $this->assertTrue(DeliveryStatusEnum::DELIVERED->canFollow(DeliveryStatusEnum::TEMPORARY_FAILURE));
        $this->assertFalse(DeliveryStatusEnum::ACCEPTED->canFollow(DeliveryStatusEnum::TEMPORARY_FAILURE));
        $this->assertFalse(DeliveryStatusEnum::TEMPORARY_FAILURE->canFollow(DeliveryStatusEnum::DELIVERED));
        $this->assertFalse(DeliveryStatusEnum::FAILED->canFollow(DeliveryStatusEnum::DELIVERED));
    }

    public function testComplaintsAndUnsubscribesDoNotChangeTheDeliveryStatus(): void
    {
        $this->assertFalse(DeliveryStatusEnum::COMPLAINED->changesDeliveryStatus());
        $this->assertFalse(DeliveryStatusEnum::UNSUBSCRIBED->changesDeliveryStatus());
        $this->assertSame('suppress_email', DeliveryStatusEnum::COMPLAINED->remediationAction());
        $this->assertSame('mailgun', DeliveryStatusEnum::DELIVERED->provider());
    }
}
