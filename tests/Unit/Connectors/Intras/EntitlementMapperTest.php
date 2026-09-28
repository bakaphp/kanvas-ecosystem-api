<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Intras;

use Kanvas\Connectors\Intras\Mappers\EntitlementMapper;
use PHPUnit\Framework\TestCase;
use stdClass;

class EntitlementMapperTest extends TestCase
{
    public function testResolvesThePlanAndItsTierToNames(): void
    {
        $plan = EntitlementMapper::planFromIntras(
            $this->planRow(['plans_id' => 4, 'plans_details_id' => 11]),
            [4 => 'Plan Corporativo'],
            [11 => '10-25 cupos'],
        );

        $this->assertSame('Plan Corporativo', $plan['plan']);
        $this->assertSame('10-25 cupos', $plan['tier']);
    }

    /**
     * The legacy "ACTIVO" status is expiration_date in the future AND tickets remaining, computed
     * in the Vue layer. The raw numbers are stored so the flatten step derives it once.
     */
    public function testSumsBaseAndAdditionalTicketsAndKeepsTheUsedCount(): void
    {
        $plan = EntitlementMapper::planFromIntras($this->planRow([
            'available_tickets' => 20,
            'additional_tickets' => 5,
            'used_tickets' => 13,
        ]));

        $this->assertSame(25, $plan['tickets']);
        $this->assertSame(13, $plan['used']);
    }

    public function testKeepsAFullyConsumedPlanWithZeroRemaining(): void
    {
        $plan = EntitlementMapper::planFromIntras($this->planRow([
            'available_tickets' => 0,
            'additional_tickets' => 0,
            'used_tickets' => 0,
            'was_consumed' => 0,
        ]));

        $this->assertSame(0, $plan['tickets'], 'zero is a real quota, not a missing one');
        $this->assertSame(0, $plan['used']);
        $this->assertFalse($plan['consumed']);
    }

    public function testTrimsLegacyDatetimesToTheDateTheGestorFiltersOn(): void
    {
        $plan = EntitlementMapper::planFromIntras($this->planRow([
            'issued_date' => '2026-01-15 09:30:00',
            'expiration_date' => '2026-12-31 23:59:59',
        ]));

        $this->assertSame('2026-01-15', $plan['issued']);
        $this->assertSame('2026-12-31', $plan['expires']);
    }

    public function testOmitsDatesThatAreAbsent(): void
    {
        $plan = EntitlementMapper::planFromIntras($this->planRow());

        $this->assertArrayNotHasKey('issued', $plan);
        $this->assertArrayNotHasKey('expires', $plan);
    }

    public function testMapsACourtesyPoolWithItsEventAndQuota(): void
    {
        $pool = EntitlementMapper::courtesyPoolFromIntras(
            $this->poolRow([
                'events_id' => 7,
                'amount' => 5,
                'issue_date' => '2026-03-01 00:00:00',
                'expiration_date' => '2026-09-30 23:59:59',
                'is_exchange' => 1,
                'comment' => ' Cliente VIP ',
            ]),
            [7 => 'Cumbre de Capital Humano'],
        );

        $this->assertSame('Cumbre de Capital Humano', $pool['evento']);
        $this->assertSame(5, $pool['amount']);
        $this->assertSame('2026-03-01', $pool['issued']);
        $this->assertSame('2026-09-30', $pool['expires']);
        $this->assertTrue($pool['es_intercambio']);
        $this->assertSame('Cliente VIP', $pool['comentario']);
    }

    public function testKeepsAnExchangeFlagOfFalse(): void
    {
        $pool = EntitlementMapper::courtesyPoolFromIntras($this->poolRow(['is_exchange' => 0]));

        $this->assertFalse($pool['es_intercambio']);
    }

    public function testMapsAPerParticipantPassWithItsDates(): void
    {
        $pass = EntitlementMapper::participantPassFromIntras($this->passRow([
            'issue_date' => '2026-02-01 08:00:00',
            'expiration_date' => '2026-08-01 23:59:59',
            'used_date' => '2026-03-15 10:22:00',
        ]));

        $this->assertSame('2026-02-01', $pass['issue_date']);
        $this->assertSame('2026-08-01', $pass['expiration_date']);
        $this->assertSame('2026-03-15', $pass['used_date']);
    }

    /**
     * An unused pass has no used_date — that absence is what marks it unconsumed, since Kanvas
     * has no separate status column.
     */
    public function testLeavesUsedDateUnsetForAnUnusedPass(): void
    {
        $pass = EntitlementMapper::participantPassFromIntras($this->passRow());

        $this->assertNull($pass['used_date']);
    }

    /**
     * Neither flag has a Kanvas column, so they ride in the payload rather than being dropped.
     */
    public function testCarriesTheExchangeAndCreditFlagsInThePayload(): void
    {
        $pass = EntitlementMapper::participantPassFromIntras($this->passRow([
            'is_exchange' => 1,
            'is_credit' => 0,
            'comment' => ' Reemplazo ',
        ]));

        $this->assertTrue($pass['payload']['es_intercambio']);
        $this->assertFalse($pass['payload']['es_credito'], 'false must survive, it is a real value');
        $this->assertSame('Reemplazo', $pass['payload']['comentario']);
    }

    private function passRow(array $overrides = []): stdClass
    {
        return $this->row([
            'issue_date' => null,
            'expiration_date' => null,
            'used_date' => null,
            'is_exchange' => null,
            'is_credit' => null,
            'comment' => null,
        ], $overrides);
    }

    private function planRow(array $overrides = []): stdClass
    {
        return $this->row([
            'plans_id' => null,
            'plans_details_id' => null,
            'available_tickets' => null,
            'additional_tickets' => null,
            'used_tickets' => null,
            'issued_date' => null,
            'expiration_date' => null,
            'was_consumed' => null,
        ], $overrides);
    }

    private function poolRow(array $overrides = []): stdClass
    {
        return $this->row([
            'events_id' => null,
            'amount' => null,
            'issue_date' => null,
            'expiration_date' => null,
            'is_exchange' => null,
            'comment' => null,
        ], $overrides);
    }

    private function row(array $defaults, array $overrides): stdClass
    {
        $row = new stdClass();

        foreach ($overrides + $defaults as $key => $value) {
            $row->{$key} = $value;
        }

        return $row;
    }
}
