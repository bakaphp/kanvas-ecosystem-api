<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Tests\TestCase;

/**
 * Proves the agnostic surface is actually reachable: the schema file is imported
 * (the #import globs are not recursive, so a new folder is easy to miss) and both
 * resolvers wire up. Neither company here has an insurer configured, so the
 * expected outcome is the factory's own error rather than a network call.
 */
class InsuranceGraphQLSchemaTest extends TestCase
{
    public function testInsuranceQuoteIsExposedAndRoutesToTheFactory(): void
    {
        $response = $this->graphQL('
            query insuranceQuote($product: String!, $input: Mixed!) {
                insuranceQuote(product: $product, input: $input) {
                    provider
                    quote_number
                    total
                }
            }
        ', ['product' => 'A-PA', 'input' => []]);

        $this->assertStringNotContainsString(
            'Cannot query field',
            (string) $response->json('errors.0.message')
        );
        $this->assertStringContainsString(
            'insurance provider',
            (string) $response->json('errors.0.message')
        );
    }

    public function testInsuranceCatalogIsExposedAndRoutesToTheFactory(): void
    {
        $response = $this->graphQL('
            query insuranceCatalog($catalog: String!) {
                insuranceCatalog(catalog: $catalog)
            }
        ', ['catalog' => 'provinces']);

        $this->assertStringNotContainsString(
            'Cannot query field',
            (string) $response->json('errors.0.message')
        );
        $this->assertStringContainsString(
            'insurance provider',
            (string) $response->json('errors.0.message')
        );
    }

    public function testTheProviderSpecificMutationsAreGone(): void
    {
        $response = $this->graphQL('
            mutation {
                universalSegurosCreateQuote(order_id: 1, product: "A-PA", input: {})
            }
        ');

        $this->assertStringContainsString(
            'universalSegurosCreateQuote',
            (string) $response->json('errors.0.message')
        );
    }
}
