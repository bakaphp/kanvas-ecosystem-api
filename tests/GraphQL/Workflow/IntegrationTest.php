<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Tests\TestCase;

class IntegrationTest extends TestCase
{
    /**
     * testCreate.
     *
     * @return void
     */
    public function testIntegrationCompanySave(): void
    {
        $response = $this->graphQL('
            query {
                integrations {
                    data {
                        id,
                        name
                    }
                }
            }');

        $this->assertArrayHasKey('id', $response->json()['data']['integrations']['data'][0]);

        $region = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $regionResponse = $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => $region
        ])->assertJson([
            'data' => ['createRegion' => $region]
        ]);
        $regionResponse = $regionResponse->decodeResponseJson();

        $integration = $response->json()['data']['integrations']['data'][0];
        $company = auth()->user()->getCurrentCompany();

        $data = [
            'integration' => [
                'id' => $integration['id']
            ],
            'company_id' => $company->getId(),
            'region' => [
                'id' => $regionResponse['data']['createRegion']['id']
            ],
            'config' => "{ \"url\": \"facebook.com\" }"
        ];

        $integrationCompanyResponse = $this->graphQL('
        mutation($data: IntegrationsCompaniesInput!) {
            integrationCompany(input: $data)
            {
                id
            }
        }', ['data' => $data]);

        $this->assertArrayHasKey('id', $integrationCompanyResponse->json()['data']['integrationCompany']);
    }

    /**
     * testSearch.
     *
     * @return void
     */
    public function testRemoveIntegrationCompany(): void
    {
        $response = $this->graphQL('
            query {
                integrations {
                    data {
                        id,
                        name
                    }
                }
            }');

        $this->assertArrayHasKey('id', $response->json()['data']['integrations']['data'][0]);

        $region = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $regionResponse = $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => $region
        ])->assertJson([
            'data' => ['createRegion' => $region]
        ]);
        $regionResponse = $regionResponse->decodeResponseJson();

        $integration = $response->json()['data']['integrations']['data'][0];
        $company = auth()->user()->getCurrentCompany();

        $data = [
            'integration' => [
                'id' => $integration['id']
            ],
            'company_id' => $company->getId(),
            'region' => [
                'id' => $regionResponse['data']['createRegion']['id']
            ],
            'config' => "{ \"url\": \"facebook.com\" }"
        ];

        $integrationCompanyResponse = $this->graphQL('
        mutation($data: IntegrationsCompaniesInput!) {
            integrationCompany(input: $data)
            {
                id
            }
        }', ['data' => $data]);

        $this->assertArrayHasKey('id', $integrationCompanyResponse->json()['data']['integrationCompany']);

        $integrationCompany = $integrationCompanyResponse->json()['data']['integrationCompany'];

        $this->graphQL('
        mutation($id: ID!) {
            removeIntegrationCompany(id: $id)
        }', ['id' => $integrationCompany['id']])->assertJson([
            'data' => ['removeIntegrationCompany' => true],
        ]);
    }
}
