<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CompensationTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    private function createEmployeeId(): string
    {
        return $this->graphQL('
            mutation($input: HrEmployeeInput!) { createHrEmployee(input: $input) { id } }
        ', ['input' => $this->makeEmployeeInput()])
            ->assertSuccessful()
            ->json('data.createHrEmployee.id');
    }

    public function testRecordCompensationAndAdminCanRead(): void
    {
        $empId = $this->createEmployeeId();

        $this->graphQL('
            mutation($input: HrCompensationInput!) {
                recordHrCompensation(input: $input) { id amount currency }
            }
        ', ['input' => ['employee_id' => $empId, 'amount' => 100000, 'currency' => 'USD', 'effective_from' => '2026-01-01']])
            ->assertSuccessful()
            ->assertJson(['data' => ['recordHrCompensation' => ['amount' => 100000, 'currency' => 'USD']]]);

        // Admin (the acting test user) can read the gated field.
        $rows = $this->graphQL('
            query { hrEmployees { data { id currentCompensation { amount } } } }
        ')
            ->assertSuccessful()
            ->json('data.hrEmployees.data');

        $match = collect($rows)->firstWhere('id', $empId);
        $this->assertNotNull($match);
        $this->assertEquals(100000, $match['currentCompensation']['amount']);
    }

    public function testCompaRatioComputedFromBand(): void
    {
        $empId = $this->createEmployeeId();

        $bandId = $this->graphQL('
            mutation($input: HrPayBandInput!) { createHrPayBand(input: $input) { id } }
        ', ['input' => ['min_amount' => 80000, 'mid_amount' => 100000, 'max_amount' => 120000, 'effective_from' => '2026-01-01']])
            ->assertSuccessful()
            ->json('data.createHrPayBand.id');

        $this->graphQL('
            mutation($input: HrCompensationInput!) {
                recordHrCompensation(input: $input) { compa_ratio }
            }
        ', ['input' => ['employee_id' => $empId, 'pay_band_id' => $bandId, 'amount' => 90000, 'effective_from' => '2026-01-01']])
            ->assertSuccessful()
            ->assertJson(['data' => ['recordHrCompensation' => ['compa_ratio' => 0.9]]]);
    }

    public function testRecordingNewCompensationClosesThePrevious(): void
    {
        $empId = $this->createEmployeeId();

        $this->graphQL('mutation($input: HrCompensationInput!){ recordHrCompensation(input:$input){ id } }', [
            'input' => ['employee_id' => $empId, 'amount' => 90000, 'effective_from' => '2026-01-01'],
        ])->assertSuccessful();

        $this->graphQL('mutation($input: HrCompensationInput!){ recordHrCompensation(input:$input){ id } }', [
            'input' => ['employee_id' => $empId, 'amount' => 100000, 'effective_from' => '2026-07-01'],
        ])->assertSuccessful();

        // currentCompensation is the latest (open) row.
        $rows = $this->graphQL('
            query { hrEmployees { data { id currentCompensation { amount } compensations { amount } } } }
        ')
            ->assertSuccessful()
            ->json('data.hrEmployees.data');

        $match = collect($rows)->firstWhere('id', $empId);
        $this->assertEquals(100000, $match['currentCompensation']['amount']);
        $this->assertCount(2, $match['compensations']);
    }
}
