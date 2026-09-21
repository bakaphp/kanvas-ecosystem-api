<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Connectors\Movipass\Enums\ParkingApplicationStatusEnum;
use Tests\TestCase;

final class ParkingApplicationStatusEnumTest extends TestCase
{
    public function testFromCorporateMapsAllFourCorporateStatuses(): void
    {
        $this->assertEquals(
            ParkingApplicationStatusEnum::PENDING,
            ParkingApplicationStatusEnum::fromCorporate(CorporateApplicationStatusEnum::PENDING)
        );
        $this->assertEquals(
            ParkingApplicationStatusEnum::NEEDS_REVIEW,
            ParkingApplicationStatusEnum::fromCorporate(CorporateApplicationStatusEnum::NEEDS_REVIEW)
        );
        $this->assertEquals(
            ParkingApplicationStatusEnum::APPROVED,
            ParkingApplicationStatusEnum::fromCorporate(CorporateApplicationStatusEnum::APPROVED)
        );
        $this->assertEquals(
            ParkingApplicationStatusEnum::REJECTED,
            ParkingApplicationStatusEnum::fromCorporate(CorporateApplicationStatusEnum::REJECTED)
        );
    }

    public function testIsOpenAndIsFinalPartitionTheStatusSet(): void
    {
        foreach (ParkingApplicationStatusEnum::cases() as $status) {
            $this->assertFalse(
                $status->isOpen() && $status->isFinal(),
                "{$status->value} cannot be both open and final"
            );
        }

        $this->assertTrue(ParkingApplicationStatusEnum::PENDING->isOpen());
        $this->assertTrue(ParkingApplicationStatusEnum::NEEDS_REVIEW->isOpen());
        $this->assertTrue(ParkingApplicationStatusEnum::NEEDS_CORRECTION->isOpen());
        $this->assertFalse(ParkingApplicationStatusEnum::APPROVED->isOpen());
        $this->assertFalse(ParkingApplicationStatusEnum::REJECTED->isOpen());
        $this->assertFalse(ParkingApplicationStatusEnum::PUBLISHED->isOpen());

        $this->assertTrue(ParkingApplicationStatusEnum::REJECTED->isFinal());
        $this->assertTrue(ParkingApplicationStatusEnum::PUBLISHED->isFinal());
        $this->assertFalse(ParkingApplicationStatusEnum::APPROVED->isFinal());
        $this->assertFalse(ParkingApplicationStatusEnum::PENDING->isFinal());
    }
}
