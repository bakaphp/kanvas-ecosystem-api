<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayBandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'hr', 'intelligence', 'social'];

    public function testCreatePayBand(): void
    {
        $this->graphQL('
            mutation($input: HrPayBandInput!) {
                createHrPayBand(input: $input) {
                    id
                    min_amount
                    mid_amount
                    max_amount
                }
            }
        ', ['input' => ['name' => 'Senior Engineer', 'min_amount' => 80000, 'mid_amount' => 100000, 'max_amount' => 120000, 'effective_from' => '2026-01-01']])
            ->assertSuccessful()
            ->assertJson(['data' => ['createHrPayBand' => [
                'min_amount' => 80000,
                'mid_amount' => 100000,
                'max_amount' => 120000,
            ]]]);
    }

    public function testUpdatePayBand(): void
    {
        $id = $this->graphQL('
            mutation($input: HrPayBandInput!) { createHrPayBand(input: $input) { id } }
        ', ['input' => ['min_amount' => 80000, 'max_amount' => 120000, 'effective_from' => '2026-01-01']])
            ->assertSuccessful()
            ->json('data.createHrPayBand.id');

        $this->graphQL('
            mutation($id: ID!, $input: UpdateHrPayBandInput!) {
                updateHrPayBand(id: $id, input: $input) { id max_amount }
            }
        ', ['id' => $id, 'input' => ['max_amount' => 130000]])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateHrPayBand' => ['max_amount' => 130000]]]);
    }

    public function testListPayBands(): void
    {
        $this->graphQL('
            query { hrPayBands { data { id min_amount } } }
        ')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['hrPayBands' => ['data']]]);
    }
}
