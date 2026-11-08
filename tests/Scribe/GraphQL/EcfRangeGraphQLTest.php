<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Tests\Scribe\ScribeTestCase;

class EcfRangeGraphQLTest extends ScribeTestCase
{
    private const string CONFIGURE = '
        mutation ($input: ScribeEcfRangeInput!) {
            configureScribeEcfRange(input: $input) {
                id document_type document_type_label first_number last_number next_number remaining expires_at status
            }
        }';

    public function test_a_range_loaded_from_the_ui_is_active_and_the_previous_one_is_kept(): void
    {
        $first = $this->configure(['document_type' => 'E31', 'first_number' => 'E310000000004', 'last_number' => 'E310000001000', 'expires_at' => '2028-12-31']);

        $this->assertSame('31', $first['document_type']);
        $this->assertSame('Factura de Crédito Fiscal Electrónica', $first['document_type_label']);
        $this->assertSame('E310000000004', $first['first_number']);
        $this->assertSame('E310000001000', $first['last_number']);
        $this->assertSame('E310000000004', $first['next_number']);
        $this->assertSame(997, $first['remaining']);
        $this->assertSame('2028-12-31', $first['expires_at']);
        $this->assertSame('ACTIVE', $first['status']);

        $second = $this->configure(['document_type' => '31', 'first_number' => '1001', 'last_number' => '2000', 'expires_at' => '2029-12-31']);

        $ranges = $this->graphQL('
            query { scribeEcfRanges(first: 10) { data { id status next_number } } }
        ')->assertSuccessful()->json('data.scribeEcfRanges.data');

        $byId = array_column($ranges, null, 'id');
        $this->assertSame('ACTIVE', $byId[$second['id']]['status']);
        $this->assertSame('E310000001001', $byId[$second['id']]['next_number']);
        $this->assertSame('SUPERSEDED', $byId[$first['id']]['status']);
        $this->assertNull($byId[$first['id']]['next_number']);
    }

    public function test_a_number_of_another_type_is_refused(): void
    {
        $this->graphQL(self::CONFIGURE, ['input' => [
            'document_type' => '31',
            'first_number' => 'E320000000001',
            'last_number' => 'E320000000100',
        ]])->assertGraphQLErrorMessage('E320000000001 is not an E31 e-NCF.');
    }

    /**
     * @param array<string, string> $input
     * @return array<string, mixed>
     */
    private function configure(array $input): array
    {
        return $this->graphQL(self::CONFIGURE, ['input' => $input])
            ->assertSuccessful()
            ->json('data.configureScribeEcfRange');
    }
}
