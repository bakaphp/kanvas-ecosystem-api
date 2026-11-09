<?php

declare(strict_types=1);

namespace Tests\Scribe\FxRates;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\AllRatesToday\AllRatesTodayCentralBankRateProvider;
use Kanvas\Scribe\FxRates\Actions\SyncCentralBankRatesAction;
use Kanvas\Scribe\FxRates\Models\FxRate;
use Kanvas\Scribe\FxRates\Services\FxRateService;
use Tests\Scribe\ScribeTestCase;

/**
 * Rates are dated far ahead so they never collide with a real synced day on the shared platform rows.
 */
class SyncCentralBankRatesTest extends ScribeTestCase
{
    private const string RATE_DATE = '2031-01-10';

    protected function afterScribeSetUp(): void
    {
        config()->set('services.allratestoday.key', 'art_live_test');
        config()->set('scribe.fx_rate_provider', AllRatesTodayCentralBankRateProvider::class);
    }

    public function test_the_banco_central_buy_rate_is_stored_once_per_published_date(): void
    {
        $this->fakeLatest(['rate' => 60.4617, 'rate_type' => 'buy', 'rate_date' => self::RATE_DATE]);

        new SyncCentralBankRatesAction()->execute();
        new SyncCentralBankRatesAction()->execute();

        $rows = FxRate::query()->where('apps_id', 0)->where('base_currency', 'USD')->where('rate_date', self::RATE_DATE)->get();
        $this->assertCount(1, $rows, 'idempotent per published date');
        $this->assertSame(60.4617, (float) $rows->first()->rate);
        $this->assertSame('central_bank:bcrd', $rows->first()->source);
        $this->assertSame('buy', $rows->first()->metadata['type']);

        // The bank, the rate type and the local currency come from the Dominican regime, not the connector.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/central-bank/bcrd/latest')
            && $request['type'] === 'buy'
            && $request['target'] === 'DOP'
            && $request->hasHeader('Authorization', 'Bearer art_live_test'));
    }

    public function test_a_rate_that_is_not_the_buy_rate_is_not_stored(): void
    {
        $this->fakeLatest(['rate' => 60.7601, 'rate_type' => 'sell', 'rate_date' => self::RATE_DATE]);

        $this->assertSame([], new SyncCentralBankRatesAction()->execute());
        $this->assertFalse(FxRate::query()->where('apps_id', 0)->where('rate_date', self::RATE_DATE)->exists());
    }

    public function test_the_rate_in_force_is_the_latest_published_on_or_before_the_date_and_never_stale(): void
    {
        FxRate::query()->create([
            'apps_id' => 0,
            'base_currency' => 'USD',
            'quote_currency' => 'DOP',
            'rate' => 60.1,
            'rate_date' => '2031-01-10',
            'source' => 'central_bank:bcrd',
        ]);

        $service = new FxRateService();

        $this->assertSame(60.1, (float) $service->rateOn(
            $this->kanvasApp,
            'USD',
            'DOP',
            Carbon::parse('2031-01-12')
        )?->rate, 'a Sunday uses Friday');
        $this->assertNull($service->rateOn(
            $this->kanvasApp,
            'USD',
            'DOP',
            Carbon::parse('2031-01-09')
        ), 'never a later rate');
        $this->assertNull($service->rateOn(
            $this->kanvasApp,
            'USD',
            'DOP',
            Carbon::parse('2031-01-30')
        ), 'older than a week is no rate');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function fakeLatest(array $body): void
    {
        Http::fake([
            AllRatesTodayCentralBankRateProvider::BASE_URL . '/api/v1/central-bank/bcrd/latest*' => Http::response([
                'bank' => 'bcrd',
                'source' => 'USD',
                'target' => 'DOP',
                ...$body,
            ]),
        ]);
    }
}
