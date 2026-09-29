<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Shipping;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Shipping\RateCards\Models\RateCard;
use Kanvas\Souk\Shipping\RateCards\Models\RateCardCountry;
use Tests\Souk\Concerns\ImportsShippingRateCards;
use Tests\TestCase;

final class ImportRateCardsActionTest extends TestCase
{
    use DatabaseTransactions;
    use ImportsShippingRateCards;

    protected $connectionsToTransact = [null, 'ecosystem', 'commerce'];

    public function testImportCreatesCardsRatesAndCountriesAndReportsCounts(): void
    {
        $company = Companies::factory()->create();

        $counts = $this->importRateCards($company, $this->rateCards());

        $this->assertSame([
            'cards_upserted' => 2,
            'cards_deleted' => 0,
            'rates_upserted' => 3,
            'rates_deleted' => 0,
            'countries_upserted' => 2,
            'countries_deleted' => 0,
        ], $counts);
        $this->assertSame(2, $this->rawCount('shipping_rate_cards', $company));
        $this->assertSame(2, $this->rawCount('shipping_rate_card_countries', $company));
        $this->assertSame(3, $this->rawRateCount($company));
    }

    public function testImportStoresCountryCodesUppercasedAndTransitOnTheRate(): void
    {
        $company = Companies::factory()->create();
        $cards = $this->rateCards();
        $cards['inposdom']['zones'] = ['us' => 'z1'];

        $this->importRateCards($company, $cards);

        $country = RateCardCountry::query()->fromCompany($company)->firstOrFail();
        $rate = RateCard::query()->fromCompany($company)->where('service_code', 'ems')->firstOrFail()->rates()->where('max_grams', 500)->firstOrFail();

        $this->assertSame('US', $country->country_code);
        $this->assertSame([3, 7], [$rate->transit_min_days, $rate->transit_max_days]);
        $this->assertSame('100.00', $rate->amount);
    }

    public function testReimportingTheSamePayloadIsIdempotent(): void
    {
        $company = Companies::factory()->create();
        $first = $this->importRateCards($company, $this->rateCards());

        $second = $this->importRateCards($company, $this->rateCards());

        $this->assertSame($first, $second);
        $this->assertSame(2, $this->rawCount('shipping_rate_cards', $company));
        $this->assertSame(2, $this->rawCount('shipping_rate_card_countries', $company));
        $this->assertSame(3, $this->rawRateCount($company));
    }

    public function testReimportUpdatesChangedValuesInPlace(): void
    {
        $company = Companies::factory()->create();
        $this->importRateCards($company, $this->rateCards());
        $cards = $this->rateCards();
        $cards['inposdom']['services']['ems']['fixed_charge'] = 80;
        $cards['inposdom']['services']['ems']['zones']['z1']['rates'][0]['amount'] = 111;

        $this->importRateCards($company, $cards);

        $card = RateCard::query()->fromCompany($company)->where('service_code', 'ems')->firstOrFail();
        $this->assertSame('80.00', $card->fixed_charge);
        $this->assertSame('111.00', $card->rates()->where('max_grams', 500)->firstOrFail()->amount);
        $this->assertSame(2, $this->rawCount('shipping_rate_cards', $company));
    }

    public function testDroppingAServiceSoftDeletesItsCardAndCascadesToItsRates(): void
    {
        $company = Companies::factory()->create();
        $this->importRateCards($company, $this->rateCards());
        $cards = $this->rateCards();
        unset($cards['inposdom']['services']['ems']);

        $counts = $this->importRateCards($company, $cards);

        $this->assertSame(1, $counts['cards_deleted']);
        $this->assertSame(2, $counts['rates_deleted']);
        $this->assertSame(1, RateCard::query()->fromCompany($company)->count());
        $this->assertSame(1, $this->rawCount('shipping_rate_cards', $company, 1));
        $this->assertSame(2, $this->rawRateCount($company, 1));
        $this->assertSame(1, $this->rawRateCount($company, 0));
    }

    public function testDroppingARateSoftDeletesOnlyThatRate(): void
    {
        $company = Companies::factory()->create();
        $this->importRateCards($company, $this->rateCards());
        $cards = $this->rateCards();
        array_pop($cards['inposdom']['services']['ems']['zones']['z1']['rates']);

        $counts = $this->importRateCards($company, $cards);

        $this->assertSame(1, $counts['rates_deleted']);
        $this->assertSame(0, $counts['cards_deleted']);
        $this->assertSame(2, $this->rawCount('shipping_rate_cards', $company, 0));
        $this->assertSame(1, $this->rawRateCount($company, 1));
    }

    public function testDroppingACountrySoftDeletesOnlyThatMapping(): void
    {
        $company = Companies::factory()->create();
        $this->importRateCards($company, $this->rateCards());
        $cards = $this->rateCards();
        unset($cards['inposdom']['zones']['ES']);

        $counts = $this->importRateCards($company, $cards);

        $this->assertSame(1, $counts['countries_deleted']);
        $this->assertSame(['US'], RateCardCountry::query()->fromCompany($company)->pluck('country_code')->all());
    }

    public function testReimportRestoresASoftDeletedRowInsteadOfInsertingADuplicate(): void
    {
        $company = Companies::factory()->create();
        $this->importRateCards($company, $this->rateCards());
        $withoutEms = $this->rateCards();
        unset($withoutEms['inposdom']['services']['ems']);
        $this->importRateCards($company, $withoutEms);

        $this->importRateCards($company, $this->rateCards());

        $this->assertSame(2, $this->rawCount('shipping_rate_cards', $company));
        $this->assertSame(2, $this->rawCount('shipping_rate_cards', $company, 0));
        $this->assertSame(3, $this->rawRateCount($company, 0));
        $this->assertSame(2, RateCard::query()->fromCompany($company)->count());
    }

    public function testSyncOnlyTouchesTheProvidersInThePayload(): void
    {
        $company = Companies::factory()->create();
        $cards = $this->rateCards() + ['other' => $this->rateCards()['inposdom']];
        $this->importRateCards($company, $cards);

        $this->importRateCards($company, $this->rateCards());

        $this->assertSame(4, RateCard::query()->fromCompany($company)->count());
    }

    public function testImportIsIsolatedPerCompany(): void
    {
        $companyA = Companies::factory()->create();
        $companyB = Companies::factory()->create();
        $this->importRateCards($companyA, $this->rateCards());
        $this->importRateCards($companyB, $this->rateCards());

        $this->importRateCards($companyA, ['inposdom' => ['zones' => [], 'services' => []]]);

        $this->assertSame(0, RateCard::query()->fromCompany($companyA)->count());
        $this->assertSame(2, RateCard::query()->fromCompany($companyB)->count());
    }

    public function testImportNormalizesTheCurrencyToUppercase(): void
    {
        $company = Companies::factory()->create();
        $cards = $this->rateCards();
        $cards['inposdom']['services']['ems']['currency'] = ' dop ';

        $this->importRateCards($company, $cards);

        $this->assertSame('DOP', RateCard::query()->fromCompany($company)->where('service_code', 'ems')->firstOrFail()->currency);
    }

    public function testMalformedPayloadsFailLoudlyAndWriteNothing(): void
    {
        $company = Companies::factory()->create();

        foreach ($this->malformedPayloads() as $case => $payload) {
            try {
                $this->importRateCards($company, $payload);
                $this->fail("Expected a ValidationException for: {$case}");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors()['rate_cards'][0], $case);
            }
        }

        $this->assertSame(0, $this->rawCount('shipping_rate_cards', $company));
        $this->assertSame(0, $this->rawCount('shipping_rate_card_countries', $company));
    }

    public function testAnInvalidProviderLaterInThePayloadPreventsEarlierProvidersFromBeingWritten(): void
    {
        $company = Companies::factory()->create();

        try {
            $this->importRateCards($company, $this->rateCards() + ['broken' => 'oops']);
            $this->fail('Expected a ValidationException');
        } catch (ValidationException) {
            $this->assertSame(0, $this->rawCount('shipping_rate_cards', $company));
        }
    }

    private function malformedPayloads(): array
    {
        return [
            'provider not an object' => ['inposdom' => 'oops'],
            'missing zones' => ['inposdom' => ['services' => []]],
            'missing services' => ['inposdom' => ['zones' => []]],
            'bad country code' => ['inposdom' => ['zones' => ['USA' => 'z1'], 'services' => []]],
            'colliding country codes' => ['inposdom' => ['zones' => ['us' => 'z1', 'US' => 'z1'], 'services' => []]],
            'empty zone name' => ['inposdom' => ['zones' => ['US' => ''], 'services' => []]],
            'service not an object' => ['inposdom' => ['zones' => [], 'services' => ['ems' => 'oops']]],
            'service without name' => $this->withService(['name' => null]),
            'bad currency' => $this->withService(['currency' => 'PESOS']),
            'negative fixed charge' => $this->withService(['fixed_charge' => -1]),
            'non numeric fixed charge' => $this->withService(['fixed_charge' => 'free']),
            'zone entry not an object' => $this->withService(['zones' => ['z1' => 'oops']]),
            'zone entry without rates' => $this->withService(['zones' => ['z1' => ['transit_min_days' => 1]]]),
            'empty rates' => $this->withService(['zones' => ['z1' => ['rates' => []]]]),
            'rate without amount' => $this->withService(['zones' => ['z1' => ['rates' => [['max_grams' => 500]]]]]),
            'rate non numeric amount' => $this->withService(['zones' => ['z1' => ['rates' => [['max_grams' => 500, 'amount' => 'free']]]]]),
            'rate zero max grams' => $this->withService(['zones' => ['z1' => ['rates' => [['max_grams' => 0, 'amount' => 1]]]]]),
            'duplicate max grams' => $this->withService(['zones' => ['z1' => ['rates' => [
                ['max_grams' => 500, 'amount' => 1],
                ['max_grams' => 500, 'amount' => 2],
            ]]]]),
            'non numeric transit' => $this->withService(['zones' => ['z1' => [
                'transit_min_days' => 'soon',
                'rates' => [['max_grams' => 500, 'amount' => 1]],
            ]]]),
        ];
    }

    private function withService(array $overrides): array
    {
        $service = array_merge($this->rateCards()['inposdom']['services']['ems'], $overrides);

        return ['inposdom' => ['zones' => ['US' => 'z1'], 'services' => ['ems' => $service]]];
    }

    private function rateCards(): array
    {
        return [
            'inposdom' => [
                'zones' => ['US' => 'z1', 'ES' => 'z2'],
                'services' => [
                    'ems' => [
                        'name' => 'EMS',
                        'currency' => 'DOP',
                        'fixed_charge' => 50,
                        'zones' => [
                            'z1' => [
                                'transit_min_days' => 3,
                                'transit_max_days' => 7,
                                'rates' => [
                                    ['max_grams' => 500, 'amount' => 100],
                                    ['max_grams' => 1000, 'amount' => 150],
                                ],
                            ],
                        ],
                    ],
                    'correo_certificado' => [
                        'name' => 'Correo Certificado',
                        'currency' => 'DOP',
                        'fixed_charge' => 0,
                        'zones' => [
                            'z2' => [
                                'rates' => [['max_grams' => 500, 'amount' => 60]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function rawCount(string $table, Companies $company, ?int $isDeleted = null): int
    {
        $query = DB::connection('commerce')
            ->table($table)
            ->where('companies_id', $company->getId())
            ->where('apps_id', app(Apps::class)->getId());

        if ($isDeleted !== null) {
            $query->where('is_deleted', $isDeleted);
        }

        return $query->count();
    }

    private function rawRateCount(Companies $company, ?int $isDeleted = null): int
    {
        $query = DB::connection('commerce')
            ->table('shipping_rate_card_rates')
            ->join('shipping_rate_cards', 'shipping_rate_cards.id', '=', 'shipping_rate_card_rates.rate_card_id')
            ->where('shipping_rate_cards.companies_id', $company->getId());

        if ($isDeleted !== null) {
            $query->where('shipping_rate_card_rates.is_deleted', $isDeleted);
        }

        return $query->count();
    }
}
