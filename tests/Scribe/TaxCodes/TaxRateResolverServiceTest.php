<?php

declare(strict_types=1);

namespace Tests\Scribe\TaxCodes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\TaxCodes\Models\TaxCode;
use Kanvas\Scribe\TaxCodes\Models\TaxRate;
use Kanvas\Scribe\TaxCodes\Services\TaxRateResolverService;
use Tests\TestCase;

class TaxRateResolverServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private TaxRateResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->service = new TaxRateResolverService();
    }

    public function test_resolve_rate_returns_active_rate_when_one_exists(): void
    {
        $code = $this->createTaxCode('ITBIS_18');
        $this->createTaxRate($code, rate: 0.18, effectiveFrom: '2020-01-01');

        $rate = $this->service->resolveRate('ITBIS_18', $this->kanvasApp);

        $this->assertNotNull($rate);
        $this->assertEquals(0.18, $rate->rate);
    }

    public function test_resolve_rate_returns_null_when_code_not_found(): void
    {
        $rate = $this->service->resolveRate('NONEXISTENT_CODE_' . uniqid(), $this->kanvasApp);

        $this->assertNull($rate);
    }

    public function test_resolve_rate_respects_effective_from_date(): void
    {
        $code = $this->createTaxCode('ITBIS_TEST_DATE_' . uniqid());
        $this->createTaxRate($code, rate: 0.18, effectiveFrom: '2025-01-01');

        $beforeEffective = Carbon::parse('2024-12-31');
        $rate = $this->service->resolveRate($code->code, $this->kanvasApp, at: $beforeEffective);

        $this->assertNull($rate, 'A rate effective 2025-01-01 must not be returned for a date in 2024.');
    }

    public function test_sort_order_tie_break_lower_sort_order_wins(): void
    {
        $code = $this->createTaxCode('ITBIS_TIE_' . uniqid());
        $this->createTaxRate($code, rate: 0.10, effectiveFrom: '2020-01-01', sortOrder: 5);
        $this->createTaxRate($code, rate: 0.18, effectiveFrom: '2020-01-01', sortOrder: 1);

        $rate = $this->service->resolveRate($code->code, $this->kanvasApp);

        $this->assertNotNull($rate);
        $this->assertEquals(0.18, $rate->rate, 'The rate with the lower sort_order (1) must win the tie.');
    }

    public function test_inactive_rate_is_skipped(): void
    {
        $code = $this->createTaxCode('ITBIS_INACTIVE_' . uniqid());
        $this->createTaxRate($code, rate: 0.18, effectiveFrom: '2020-01-01', isActive: false);

        $rate = $this->service->resolveRate($code->code, $this->kanvasApp);

        $this->assertNull($rate, 'An inactive rate must not be returned even if it is the most recent.');
    }

    public function test_active_rate_on_model_returns_null_when_no_rates_exist(): void
    {
        $code = $this->createTaxCode('ITBIS_EMPTY_' . uniqid());

        $rate = $code->activeRate();

        $this->assertNull($rate);
    }

    private function createTaxCode(string $code): TaxCode
    {
        $taxCode = new TaxCode();
        $taxCode->apps_id = $this->kanvasApp->getId();
        $taxCode->companies_id = static::$cachedUser->getCurrentCompany()->getId();
        $taxCode->code = $code;
        $taxCode->name = 'Test Tax Code ' . $code;
        $taxCode->is_active = true;
        $taxCode->source = 'test';
        $taxCode->save();

        return $taxCode;
    }

    private function createTaxRate(
        TaxCode $taxCode,
        float $rate,
        string $effectiveFrom,
        int $sortOrder = 0,
        bool $isActive = true,
    ): TaxRate {
        $taxRate = new TaxRate();
        $taxRate->tax_code_id = $taxCode->id;
        $taxRate->name = 'Rate ' . $rate;
        $taxRate->rate = $rate;
        $taxRate->sort_order = $sortOrder;
        $taxRate->is_active = $isActive;
        $taxRate->effective_from = Carbon::parse($effectiveFrom);
        $taxRate->save();

        return $taxRate;
    }
}
