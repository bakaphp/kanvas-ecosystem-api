<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Mappers\RegistrationMapper;
use PHPUnit\Framework\TestCase;
use stdClass;

class RegistrationMapperTest extends TestCase
{
    public function testCountsTheLegacyAttendingInscriptionTypes(): void
    {
        foreach ([1, 2, 6, 7, 8, 9, 11, 14] as $typeId) {
            $this->assertTrue(
                RegistrationMapper::isAttending($typeId),
                "inscription type {$typeId} should count as attending"
            );
        }
    }

    public function testExcludesTheNonAttendingInscriptionTypes(): void
    {
        // 3 cancelled, 4 interested, 5 reserved, 10 interested-in-event.
        foreach ([3, 4, 5, 10] as $typeId) {
            $this->assertFalse(
                RegistrationMapper::isAttending($typeId),
                "inscription type {$typeId} should not count as attending"
            );
        }
    }

    /**
     * Every legacy report used IN (1,2,6,7,8,9,11,14) except participants_profiles, which used
     * IN (1,2,7,8,6,9) — dropping 11 (program) and 14 (sponsor guest). Two definitions of
     * "attended" in one system; these two are the difference and must stay included.
     */
    public function testIncludesTheTwoTypesTheProfilesReportDropped(): void
    {
        $this->assertTrue(RegistrationMapper::isAttending(11));
        $this->assertTrue(RegistrationMapper::isAttending(14));
    }

    public function testTreatsAMissingInscriptionTypeAsNotAttending(): void
    {
        $this->assertFalse(RegistrationMapper::isAttending(null));
    }

    public function testMapsMoneyAndKeepsTheLegacyExtrasInMetadata(): void
    {
        $row = new stdClass();
        $row->investment = 1500.50;
        $row->discount = 100;
        $row->invoice_date = '2026-02-14';
        $row->reserved_tickets = 3;
        $row->comments = 'pago por transferencia';

        $mapped = RegistrationMapper::fromIntras($row);

        $this->assertSame(1500.50, $mapped['ticket_price']);
        $this->assertSame(100, $mapped['discount']);
        $this->assertSame('2026-02-14', $mapped['invoice_date']);
        $this->assertSame(3, $mapped['metadata']['reserved_tickets']);
        $this->assertSame('pago por transferencia', $mapped['metadata']['comments']);
    }
}
