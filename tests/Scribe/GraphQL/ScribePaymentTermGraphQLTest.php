<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ScribePaymentTermGraphQLTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private function createTerm(string $namePrefix, bool $isDefault, ?float $discountPct = null): array
    {
        $input = [
            'name' => $namePrefix . ' ' . uniqid('', true),
            'net_days' => 30,
            'is_default' => $isDefault,
        ];

        if ($discountPct !== null) {
            $input['discount_days'] = 10;
            $input['discount_pct'] = $discountPct;
        }

        $response = $this->graphQL('
            mutation($input: ScribePaymentTermInput!) {
                createScribePaymentTerm(input: $input) {
                    id
                    name
                    is_default
                }
            }
        ', ['input' => $input])->assertSuccessful();

        return $response->json('data.createScribePaymentTerm');
    }

    public function testPaymentTermsSortByIsDefaultPutsDefaultFirst(): void
    {
        $default = $this->createTerm('Default Term', true);
        $regular = $this->createTerm('Regular Term', false);

        $this->assertGreaterThan((int) $default['id'], (int) $regular['id']);

        $response = $this->graphQL('
            query($names: Mixed!) {
                scribePaymentTerms(
                    where: { column: NAME, operator: IN, value: $names }
                    orderBy: [{ column: IS_DEFAULT, order: DESC }]
                ) {
                    data {
                        id
                        is_default
                    }
                }
            }
        ', ['names' => [$default['name'], $regular['name']]])->assertSuccessful();

        $rows = $response->json('data.scribePaymentTerms.data');

        $this->assertCount(2, $rows);
        $this->assertSame((int) $default['id'], (int) $rows[0]['id']);
        $this->assertTrue($rows[0]['is_default']);
        $this->assertSame((int) $regular['id'], (int) $rows[1]['id']);
        $this->assertFalse($rows[1]['is_default']);

        $ascending = $this->graphQL('
            query($names: Mixed!) {
                scribePaymentTerms(
                    where: { column: NAME, operator: IN, value: $names }
                    orderBy: [{ column: IS_DEFAULT, order: ASC }]
                ) {
                    data {
                        id
                    }
                }
            }
        ', ['names' => [$default['name'], $regular['name']]])->assertSuccessful();

        $this->assertSame(
            (int) $regular['id'],
            (int) $ascending->json('data.scribePaymentTerms.data.0.id')
        );
    }

    public function testPaymentTermsFilterByIsDefault(): void
    {
        $default = $this->createTerm('Default Term', true);
        $regular = $this->createTerm('Regular Term', false);

        $response = $this->graphQL('
            query($names: Mixed!) {
                scribePaymentTerms(
                    where: {
                        AND: [
                            { column: NAME, operator: IN, value: $names }
                            { column: IS_DEFAULT, operator: EQ, value: true }
                        ]
                    }
                ) {
                    data {
                        id
                        is_default
                    }
                }
            }
        ', ['names' => [$default['name'], $regular['name']]])->assertSuccessful();

        $rows = $response->json('data.scribePaymentTerms.data');

        $this->assertCount(1, $rows);
        $this->assertSame((int) $default['id'], (int) $rows[0]['id']);
        $this->assertTrue($rows[0]['is_default']);
    }

    public function testDiscountPercentReturnsStoredDiscountPct(): void
    {
        $term = $this->createTerm('Discount Term', false, 0.02);

        $response = $this->graphQL('
            query($name: Mixed!) {
                scribePaymentTerms(where: { column: NAME, operator: EQ, value: $name }) {
                    data {
                        id
                        discount_days
                        discount_percent
                    }
                }
            }
        ', ['name' => $term['name']])->assertSuccessful();

        $rows = $response->json('data.scribePaymentTerms.data');

        $this->assertCount(1, $rows);
        $this->assertSame(10, $rows[0]['discount_days']);
        $this->assertEqualsWithDelta(0.02, $rows[0]['discount_percent'], 0.0000001);
    }
}
