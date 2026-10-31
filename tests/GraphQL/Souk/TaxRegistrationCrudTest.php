<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Models\TaxRegistration;
use Tests\TestCase;

class TaxRegistrationCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['commerce'];

    protected Apps $kanvasApp;

    public function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
    }

    public function testCreateTaxRegistration(): void
    {
        $input = [
            'jurisdiction_country' => 'DO',
            'registration_number' => 'RNC-' . uniqid(),
            'provider' => 'manual',
            'is_active' => true,
        ];

        $response = $this->graphQL('
            mutation($input: TaxRegistrationInput!) {
                createTaxRegistration(input: $input) {
                    id
                    uuid
                    jurisdiction_country
                    registration_number
                    provider
                    is_active
                }
            }
        ', ['input' => $input]);

        $response->assertSuccessful();
        $response->assertJson([
            'data' => [
                'createTaxRegistration' => [
                    'jurisdiction_country' => 'DO',
                    'registration_number' => $input['registration_number'],
                    'provider' => 'manual',
                    'is_active' => true,
                ],
            ],
        ]);
    }

    public function testCreateTaxRegistrationOverlapRejected(): void
    {
        $firstInput = [
            'jurisdiction_country' => 'US',
            'provider' => 'manual',
            'effective_from' => '2024-01-01',
            'effective_to' => '2024-12-31',
        ];

        $this->graphQL('
            mutation($input: TaxRegistrationInput!) {
                createTaxRegistration(input: $input) { id }
            }
        ', ['input' => $firstInput])->assertGraphQLErrorFree();

        $overlapInput = [
            'jurisdiction_country' => 'US',
            'provider' => 'manual',
            'effective_from' => '2024-06-01',
            'effective_to' => '2025-06-30',
        ];

        $this->graphQL('
            mutation($input: TaxRegistrationInput!) {
                createTaxRegistration(input: $input) { id }
            }
        ', ['input' => $overlapInput])->assertGraphQLErrorMessage(
            'A tax registration for this jurisdiction already exists in the specified date range.'
        );
    }

    public function testUpdateTaxRegistration(): void
    {
        $createInput = [
            'jurisdiction_country' => 'MX',
            'registration_number' => 'RFC-' . uniqid(),
            'provider' => 'manual',
        ];

        $createResponse = $this->graphQL('
            mutation($input: TaxRegistrationInput!) {
                createTaxRegistration(input: $input) { id }
            }
        ', ['input' => $createInput]);
        $createResponse->assertSuccessful();

        $id = $createResponse->json('data.createTaxRegistration.id');

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: UpdateTaxRegistrationInput!) {
                updateTaxRegistration(id: $id, input: $input) {
                    id
                    registration_number
                }
            }
        ', [
            'id' => $id,
            'input' => ['registration_number' => 'RFC-UPDATED'],
        ]);

        $updateResponse->assertSuccessful();
        $updateResponse->assertJson([
            'data' => [
                'updateTaxRegistration' => [
                    'registration_number' => 'RFC-UPDATED',
                ],
            ],
        ]);
    }

    public function testDeleteTaxRegistration(): void
    {
        $createInput = [
            'jurisdiction_country' => 'CO',
            'provider' => 'manual',
        ];

        $createResponse = $this->graphQL('
            mutation($input: TaxRegistrationInput!) {
                createTaxRegistration(input: $input) { id }
            }
        ', ['input' => $createInput]);
        $createResponse->assertSuccessful();

        $id = $createResponse->json('data.createTaxRegistration.id');

        $this->graphQL('
            mutation($id: ID!) {
                deleteTaxRegistration(id: $id)
            }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteTaxRegistration' => true]]);

        $this->assertDatabaseMissing('tax_registrations', [
            'id' => $id,
            'is_deleted' => 0,
        ], 'commerce');
    }

    public function testTaxRegistrationQueryIsScopedToApp(): void
    {
        $this->graphQL('
            mutation($input: TaxRegistrationInput!) {
                createTaxRegistration(input: $input) { id }
            }
        ', ['input' => ['jurisdiction_country' => 'ZZ', 'provider' => 'manual']])
        ->assertGraphQLErrorFree();

        $queryResponse = $this->graphQL('
            query {
                taxRegistrations(first: 100) {
                    data {
                        id
                        jurisdiction_country
                    }
                }
            }
        ');

        $queryResponse->assertSuccessful();

        $rows = collect($queryResponse->json('data.taxRegistrations.data'));
        $this->assertNotEmpty($rows->where('jurisdiction_country', 'ZZ')->all());

        foreach ($rows as $row) {
            $dbRow = TaxRegistration::query()
                ->where('id', $row['id'])
                ->notDeleted()
                ->first();
            $this->assertNotNull($dbRow);
            $this->assertEquals($this->kanvasApp->getId(), $dbRow->apps_id);
        }
    }
}
