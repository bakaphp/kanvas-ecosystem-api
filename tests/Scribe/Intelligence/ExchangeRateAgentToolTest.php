<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\AllRatesToday\AllRatesTodayMarketRateProvider;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GetExchangeRateTool;
use Kanvas\Scribe\FxRates\Models\FxRate;
use Kanvas\Scribe\FxRates\Services\FxRateService;
use Tests\Scribe\ScribeTestCase;

/**
 * The official pairs are ones the daily sync never stores (it stores USD only), so a real synced row on the shared
 * platform table cannot outrank the one a test seeds.
 */
class ExchangeRateAgentToolTest extends ScribeTestCase
{
    protected function afterScribeSetUp(): void
    {
        config()->set('services.allratestoday.key', 'art_live_test');
        config()->set('scribe.market_rate_provider', AllRatesTodayMarketRateProvider::class);
        // A local run caches in Redis, where a faked rate would outlive the test and answer a real lookup.
        config()->set('cache.default', 'array');
    }

    public function test_a_pair_with_an_official_rate_on_file_is_answered_from_it_in_both_directions(): void
    {
        Http::fake();
        $published = Carbon::today()->subDay()->toDateString();
        FxRate::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'base_currency' => 'CHF',
            'quote_currency' => 'DOP',
            'rate' => 70.5,
            'rate_date' => $published,
            'source' => 'central_bank:test',
            'metadata' => ['type' => 'buy'],
        ]);

        $direct = $this->tool()->__invoke(from_currency: 'chf', to_currency: 'dop', amount: 100.0);

        $this->assertTrue($direct['success'], json_encode($direct));
        $this->assertSame(GetExchangeRateTool::KIND_OFFICIAL, $direct['kind']);
        $this->assertSame(70.5, $direct['rate']);
        $this->assertSame($published, $direct['as_of'], 'the latest one published on or before today');
        $this->assertSame('central_bank:test (buy)', $direct['source']);
        $this->assertSame(7050.0, $direct['converted_amount']);
        $this->assertStringContainsString('1 CHF = 70.5 DOP, the official rate published ' . $published, $direct['message']);

        $inverse = $this->tool()->__invoke(from_currency: 'DOP', to_currency: 'CHF', amount: 7050.0);

        $this->assertSame(GetExchangeRateTool::KIND_OFFICIAL, $inverse['kind']);
        $this->assertSame(round(1 / 70.5, 10), $inverse['rate']);
        $this->assertSame(100.0, $inverse['converted_amount']);

        Http::assertNothingSent();
    }

    public function test_any_other_pair_gets_the_live_market_rate_which_is_cached_and_never_stored(): void
    {
        Http::fake([
            'allratestoday.com/api/v1/rates*' => Http::response($this->liveQuote('MXN', 'DOP', 3.2154)),
        ]);
        $rowsBefore = FxRate::query()->count();

        $result = $this->tool()->__invoke(from_currency: 'MXN', to_currency: 'DOP', amount: 1000.0);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(GetExchangeRateTool::KIND_MARKET, $result['kind']);
        $this->assertSame(3.2154, $result['rate']);
        $this->assertSame(3215.4, $result['converted_amount']);
        $this->assertSame('allratestoday (mid-market)', $result['source']);
        $this->assertStringContainsString('not an official central-bank rate', $result['message']);

        $this->assertSame(3.2154, $this->tool()->__invoke(from_currency: 'MXN', to_currency: 'DOP')['rate']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/rates')
            && $request['source'] === 'MXN'
            && $request['target'] === 'DOP'
            && $request->hasHeader('Authorization', 'Bearer art_live_test'));

        // fx_rates is what a fiscal document freezes its declared rate from; a market rate must never reach it.
        $this->assertSame($rowsBefore, FxRate::query()->count());
        $this->assertNull(new FxRateService()->rateOn(
            $this->kanvasApp,
            'MXN',
            'DOP',
            Carbon::today()
        ));
    }

    public function test_any_date_but_today_is_answered_only_from_official_rates(): void
    {
        Http::fake();

        $otherDays = [
            Carbon::today()->subDays(30),
            Carbon::today()->addDays(3),
        ];

        foreach ($otherDays as $day) {
            $result = $this->tool()
                ->__invoke(from_currency: 'JPY', to_currency: 'BRL', date: $day->toDateString());

            $this->assertFalse($result['success'] ?? false);
            $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $result['outcome']);
            $this->assertStringContainsString('today only', $result['message']);
        }

        Http::assertNothingSent();
    }

    public function test_today_is_the_companys_own_day_not_the_servers(): void
    {
        Http::fake([
            'allratestoday.com/api/v1/rates*' => Http::response($this->liveQuote('MXN', 'DOP', 3.2154)),
        ]);
        // 21:00 on the 10th in Santo Domingo, already the 11th on the server.
        Carbon::setTestNow(Carbon::parse('2031-01-11 01:00:00', 'UTC'));
        $dominican = clone $this->company;
        $dominican->timezone = 'America/Santo_Domingo';

        $dated = $this->tool($dominican)
            ->__invoke(from_currency: 'MXN', to_currency: 'DOP', date: '2031-01-10');
        $undated = $this->tool($dominican)->__invoke(from_currency: 'MXN', to_currency: 'DOP');

        $this->assertTrue($dated['success'] ?? false, json_encode($dated));
        $this->assertSame(3.2154, $dated['rate']);
        $this->assertSame('2031-01-10', $undated['requested_date']);
    }

    public function test_a_pair_the_provider_has_no_quote_for_is_not_found_and_not_asked_twice(): void
    {
        Http::fake(['allratestoday.com/api/v1/rates*' => Http::response(['error' => 'Unknown currency'], 404)]);

        $first = $this->tool()->__invoke(from_currency: 'XXX', to_currency: 'DOP');
        $second = $this->tool()->__invoke(from_currency: 'XXX', to_currency: 'DOP');

        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $first['outcome']);
        $this->assertSame(ToolOutcomeEnum::NOT_FOUND->value, $second['outcome']);
        $this->assertArrayNotHasKey('rate', $first);
        Http::assertSentCount(1);
    }

    public function test_a_provider_failure_reads_as_a_failure_and_the_next_call_tries_again(): void
    {
        Http::fake([
            'allratestoday.com/api/v1/rates*' => Http::sequence()
                ->push(['error' => 'Request limit exceeded'], 429)
                ->push($this->liveQuote('SEK', 'NOK', 0.9412)),
        ]);

        $failed = $this->tool()->__invoke(from_currency: 'SEK', to_currency: 'NOK');

        $this->assertFalse($failed['success']);
        $this->assertSame(ToolOutcomeEnum::PROVIDER_ERROR->value, $failed['outcome']);
        $this->assertArrayNotHasKey('rate', $failed);

        $this->assertSame(0.9412, $this->tool()->__invoke(from_currency: 'SEK', to_currency: 'NOK')['rate']);
    }

    public function test_the_target_defaults_to_the_companys_fiscal_currency_and_bad_input_is_refused(): void
    {
        Http::fake();
        FxRate::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'base_currency' => 'CHF',
            'quote_currency' => 'DOP',
            'rate' => 70.5,
            'rate_date' => Carbon::today()->toDateString(),
            'source' => 'central_bank:test',
        ]);

        $dominican = clone $this->company;
        $dominican->country_code = 'DO';
        $defaulted = $this->tool($dominican)->__invoke(from_currency: 'CHF', to_currency: ' ', date: '');
        $this->assertSame('DOP', $defaulted['to_currency'], json_encode($defaulted));
        $this->assertSame(Carbon::today()->toDateString(), $defaulted['requested_date'], 'a blank date is today');
        $this->assertSame(70.5, $defaulted['rate']);

        $noRegime = clone $this->company;
        $noRegime->country_code = 'US';
        $undefaulted = $this->tool($noRegime)->__invoke(from_currency: 'CHF');
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $undefaulted['outcome']);

        $badInputs = [
            'the same currency twice' => [
                'from_currency' => 'CHF',
                'to_currency' => 'CHF',
            ],
            'not a currency code' => [
                'from_currency' => 'dollars',
                'to_currency' => 'DOP',
            ],
            'not a date' => [
                'from_currency' => 'CHF',
                'to_currency' => 'DOP',
                'date' => 'next week',
            ],
            'a negative amount' => [
                'from_currency' => 'CHF',
                'to_currency' => 'DOP',
                'amount' => -5.0,
            ],
        ];

        foreach ($badInputs as $arguments) {
            $refused = $this->tool()->__invoke(...$arguments);
            $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $refused['outcome'], json_encode($arguments));
            $this->assertFalse($refused['success']);
        }

        Http::assertNothingSent();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveQuote(string $source, string $target, float $rate): array
    {
        return [
            [
                'rate' => $rate,
                'source' => $source,
                'target' => $target,
                'time' => '2031-01-10T12:00:00Z',
            ],
        ];
    }

    private function tool(?Companies $company = null): GetExchangeRateTool
    {
        return new GetExchangeRateTool()
            ->withContext($this->kanvasApp, $company ?? $this->company, static::$cachedUser);
    }
}
