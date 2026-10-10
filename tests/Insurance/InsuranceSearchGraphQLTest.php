<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Insurance\Services\InsuranceQuoteBinding;
use RuntimeException;
use Tests\Insurance\Concerns\BuildsSearchCatalog;
use Tests\TestCase;

class InsuranceSearchGraphQLTest extends TestCase
{
    use DatabaseTransactions;
    use BuildsSearchCatalog;

    protected $connectionsToTransact = [null, 'commerce'];

    private const SEARCH = '
        query($input: CanonicalQuoteInput!) {
            insuranceSearch(input: $input) {
                entries {
                    rank
                    insurer { name }
                    plan { id name type }
                    quote_number
                    price
                    can_emit
                    is_sponsored
                    is_recommended
                }
                failures { insurer { name } reason message }
            }
        }
    ';

    private function variables(string $category = 'FULL'): array
    {
        return [
            'input' => [
                'category' => $category,
                'vehicle' => [
                    'brand' => 'Toyota',
                    'model' => 'Corolla ' . uniqid(),
                    'year' => 2022,
                    'fuel_type' => 'GASOLINE_DIESEL',
                    'estimated_value' => 1000000,
                ],
            ],
        ];
    }

    public function testACustomerGetsARankedComparisonWithFailuresReported(): void
    {
        $user = $this->createUser();
        $this->actingAs($user, 'api');
        $company = $user->getCurrentCompany();
        $this->pinPlatformCompany($company->getId());
        $suffix = uniqid();

        $this->bindFakeProvider('ok_' . $suffix, fn (string $code) => $this->quoteResult('Q-' . $code, 200.0, 20.0));
        $this->bindFakeProvider('boom_' . $suffix, fn () => throw new RuntimeException('down'));

        $okInsurer = $this->seedInsurer($company, 'ok_' . $suffix);
        $this->seedPlan($okInsurer, 'CHEAP', ['name' => 'Cheap']);
        $this->seedPlan($okInsurer, 'PROMO', ['name' => 'Promo', 'sponsored' => true]);
        $this->seedPlan($this->seedInsurer($company, 'boom_' . $suffix), 'X');

        $response = $this->graphQL(self::SEARCH, $this->variables())->assertSuccessful();

        $data = $response->json('data.insuranceSearch');
        $this->assertNotNull($data, json_encode($response->json()));
        $this->assertCount(2, $data['entries']);
        $this->assertSame('Promo', $data['entries'][0]['plan']['name']);
        $this->assertSame(1, $data['entries'][0]['rank']);
        $this->assertTrue($data['entries'][0]['is_sponsored']);
        $this->assertSame('FULL', $data['entries'][0]['plan']['type']);
        $this->assertSame('Q-PROMO', $data['entries'][0]['quote_number']);
        $this->assertEquals(200.0, $data['entries'][0]['price']);
        $this->assertSame(
            $data['entries'][0]['plan']['id'],
            (string) InsuranceQuoteBinding::planIdFor(app(Apps::class)->getId(), $user->getId(), 'Q-PROMO')
        );
        $this->assertTrue($data['entries'][0]['can_emit']);
        $this->assertSame('Cheap', $data['entries'][1]['plan']['name']);
        $this->assertSame(2, $data['entries'][1]['rank']);

        $this->assertCount(1, $data['failures']);
        $this->assertSame('UNAVAILABLE', $data['failures'][0]['reason']);
    }

    public function testCommissionAndScoreAreNotPartOfTheCustomerSchema(): void
    {
        foreach (['commission', 'recommended_score', 'commission_rate'] as $field) {
            $response = $this->graphQL(
                'query { insuranceSearch(input: {category: FULL, vehicle: {brand: "a", model: "b"}}) { entries { ' . $field . ' } } }'
            );

            $this->assertNotEmpty($response->json('errors'), $field);
            $this->assertStringContainsString('Cannot query field', $response->json('errors.0.message'));
        }
    }

    public function testUnauthenticatedCallersAreRejected(): void
    {
        $this->app['auth']->forgetGuards();

        $this->graphQL(self::SEARCH, $this->variables())->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function testAVehicleWithoutBrandAndModelIsRejected(): void
    {
        $response = $this->graphQL(self::SEARCH, ['input' => ['category' => 'LAW', 'vehicle' => ['year' => 2020]]]);

        $this->assertNotEmpty($response->json('errors'));
    }
}
