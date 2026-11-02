<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Models\CustomerTaxExemption;
use Tests\TestCase;

class CustomerTaxExemptionCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['commerce'];

    protected Apps $kanvasApp;

    public function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
    }

    public function testCreateCustomerTaxExemptionPersistsJurisdiction(): void
    {
        $peopleId = random_int(900000000, 999999999);

        $response = $this->graphQL('
            mutation($input: CustomerTaxExemptionInput!) {
                createCustomerTaxExemption(input: $input) {
                    id
                    people_id
                    exemption_type
                    jurisdiction_country
                    jurisdiction_state
                }
            }
        ', ['input' => [
            'people_id' => $peopleId,
            'exemption_type' => 'resale',
            'jurisdiction_country' => 'do',
            'certificate_number' => 'CERT-' . uniqid(),
        ]]);

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'createCustomerTaxExemption' => [
                    'people_id' => (string) $peopleId,
                    'exemption_type' => 'resale',
                    'jurisdiction_country' => 'DO',
                    'jurisdiction_state' => null,
                ],
            ],
        ]);

        $this->assertSame(
            (int) $response->json('data.createCustomerTaxExemption.id'),
            CustomerTaxExemption::activeForPeople($this->kanvasApp, $peopleId, 'DO')?->getId()
        );
        $this->assertNull(CustomerTaxExemption::activeForPeople($this->kanvasApp, $peopleId, 'US'));
    }

    public function testUpdateCustomerTaxExemptionJurisdiction(): void
    {
        $createResponse = $this->graphQL('
            mutation($input: CustomerTaxExemptionInput!) {
                createCustomerTaxExemption(input: $input) { id }
            }
        ', ['input' => [
            'people_id' => random_int(900000000, 999999999),
            'exemption_type' => 'government',
            'jurisdiction_country' => 'US',
            'jurisdiction_state' => 'TX',
        ]]);
        $createResponse->assertSuccessful();

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: UpdateCustomerTaxExemptionInput!) {
                updateCustomerTaxExemption(id: $id, input: $input) {
                    jurisdiction_country
                    jurisdiction_state
                    certificate_number
                }
            }
        ', [
            'id' => $createResponse->json('data.createCustomerTaxExemption.id'),
            'input' => [
                'jurisdiction_country' => 'mx',
                'jurisdiction_state' => null,
                'certificate_number' => 'CERT-UPDATED',
            ],
        ]);

        $updateResponse->assertSuccessful();
        $updateResponse->assertJson([
            'data' => [
                'updateCustomerTaxExemption' => [
                    'jurisdiction_country' => 'MX',
                    'jurisdiction_state' => null,
                    'certificate_number' => 'CERT-UPDATED',
                ],
            ],
        ]);
    }
}
