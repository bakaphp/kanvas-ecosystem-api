<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

class HrLedgerEventTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence'];

    private function createEmployeeId(): string
    {
        return $this->graphQL('
            mutation($input: HrEmployeeInput!) { createHrEmployee(input: $input) { id } }
        ', ['input' => $this->makeEmployeeInput()])
            ->assertSuccessful()
            ->json('data.createHrEmployee.id');
    }

    public function testEmployeeCreatedEmitsHumanResourcesLedgerEvent(): void
    {
        $empId = $this->createEmployeeId();

        $event = Event::query()
            ->where('source_domain', 'HumanResources')
            ->where('event_type', 'employee.created')
            ->where('source_entity_id', (int) $empId)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals('HumanResources', $event->source_domain);
    }

    public function testCompensationChangeIsAuditedWithoutLeakingTheAmount(): void
    {
        $empId = $this->createEmployeeId();

        $this->graphQL('
            mutation($input: HrCompensationInput!) { recordHrCompensation(input: $input) { id } }
        ', ['input' => ['employee_id' => $empId, 'amount' => 123456, 'effective_from' => '2026-01-01']])
            ->assertSuccessful();

        $event = Event::query()
            ->where('source_domain', 'HumanResources')
            ->where('event_type', 'compensation.changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        // The ledger records that comp changed, but never the salary figure itself.
        $this->assertStringNotContainsString('123456', json_encode($event->payload));
    }
}
