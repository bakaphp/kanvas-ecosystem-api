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

    /**
     * `channels_id` is set on 54,089 of SIPGO's 133,813 registrations and was never imported, so
     * "¿por qué canal se inscribió la gente?" — a routine question here — had no answer at all.
     * The name is resolved at import time because the flatten step cannot join a legacy catalog.
     */
    public function testResolvesTheAcquisitionChannelToItsName(): void
    {
        $row = new stdClass();
        $row->channels_id = 8;

        $mapped = RegistrationMapper::fromIntras($row, [8 => 'REDES SOCIALES INTRAS']);

        $this->assertSame('REDES SOCIALES INTRAS', $mapped['metadata']['canal']);
    }

    public function testResolvesThePlanAndKeepsItsLegacyId(): void
    {
        $row = new stdClass();
        $row->companies_plans_id = 77;

        $mapped = RegistrationMapper::fromIntras($row, [], [77 => 'Plan Corporativo']);

        $this->assertSame('Plan Corporativo', $mapped['metadata']['plan']);
        $this->assertSame(77, $mapped['metadata']['plan_id']);
    }

    /**
     * A raw legacy id is not a channel name, and writing one would put "999" in a column the
     * agent groups reports by.
     */
    public function testAnUnresolvedLookupIsOmittedRatherThanStoredAsAnId(): void
    {
        $row = new stdClass();
        $row->channels_id = 999;

        $mapped = RegistrationMapper::fromIntras($row);

        $this->assertArrayNotHasKey('canal', $mapped['metadata']);
    }

    public function testARegistrationWithNoChannelLeavesTheKeyAbsent(): void
    {
        $mapped = RegistrationMapper::fromIntras(new stdClass(), [8 => 'REDES SOCIALES INTRAS']);

        $this->assertArrayNotHasKey('canal', $mapped['metadata']);
        $this->assertArrayNotHasKey('plan_id', $mapped['metadata']);
    }

    /**
     * The legacy column is a DATETIME and the Kanvas one a DATE. Keeping the time half made
     * every already-imported registration compare as changed, so the importer rewrote all 133k
     * rows on every run once it started updating instead of only creating.
     */
    public function testTrimsTheTimeHalfOffTheInvoiceDate(): void
    {
        $row = new stdClass();
        $row->invoice_date = '2017-11-26 00:00:00';

        $this->assertSame('2017-11-26', RegistrationMapper::fromIntras($row)['invoice_date']);
    }

    /**
     * SIPGO writes MySQL zero dates for "never invoiced".
     */
    public function testAZeroDateBecomesNullRatherThanAnInvalidDate(): void
    {
        $row = new stdClass();
        $row->invoice_date = '0000-00-00 00:00:00';

        $this->assertNull(RegistrationMapper::fromIntras($row)['invoice_date']);
        $this->assertNull(RegistrationMapper::invoiceDate(''));
        $this->assertNull(RegistrationMapper::invoiceDate(null));
    }

    /**
     * The filter drops nulls only. A bare `array_filter` also drops `false`, which erased the
     * attendance flags entirely — and `reserved_tickets => 0`, which the flatten step reads.
     */
    public function testFalseFlagsSurviveTheFilter(): void
    {
        $row = new stdClass();
        $row->is_assisting = 0;
        $row->assisted_event = 0;

        $mapped = RegistrationMapper::fromIntras($row);

        $this->assertFalse($mapped['metadata']['is_assisting']);
        $this->assertFalse($mapped['metadata']['assisted_event']);
    }
}
