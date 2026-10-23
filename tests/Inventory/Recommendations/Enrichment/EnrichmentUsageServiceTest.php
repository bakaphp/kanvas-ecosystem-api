<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations\Enrichment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Inventory\Recommendations\Enrichment\Services\EnrichmentUsageService;
use Tests\TestCase;

class EnrichmentUsageServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['intelligence'];

    public function testPricesEachProductAndTotalsTheRun(): void
    {
        $this->seedPricing('enrichment-priced-test', 0.10, 0.40);

        $usage = app(EnrichmentUsageService::class);

        $first = $usage->record(['input_tokens' => 1_300, 'output_tokens' => 180, 'model' => 'enrichment-priced-test']);
        $usage->record(['input_tokens' => 700, 'output_tokens' => 20, 'model' => 'enrichment-priced-test']);

        // 1300 * 0.10 / 1M + 180 * 0.40 / 1M
        $this->assertSame('1300 in / 180 out tokens, $0.000202 (enrichment-priced-test)', $first);
        $this->assertSame(
            ['Tokens: 2000 in / 200 out. Estimated cost: $0.000280.'],
            $usage->totals(),
        );
    }

    public function testAnUnknownModelPrintsTokensWithNaCostAndStaysOutOfTheTotal(): void
    {
        $this->seedPricing('enrichment-priced-test', 1.0, 2.0);

        $usage = app(EnrichmentUsageService::class);

        $unknown = $usage->record(['input_tokens' => 500, 'output_tokens' => 50, 'model' => 'enrichment-unpriced-test']);
        $usage->record(['input_tokens' => 1_000_000, 'output_tokens' => 500_000, 'model' => 'enrichment-priced-test']);

        $this->assertSame('500 in / 50 out tokens, n/a (enrichment-unpriced-test)', $unknown);
        $this->assertSame(
            [
                'Tokens: 1000500 in / 500050 out. Estimated cost: $2.000000.',
                '1 product(s) ran on a model with no model_pricing row and are not in that cost.',
            ],
            $usage->totals(),
        );
    }

    public function testAMissingModelIsNotPriced(): void
    {
        $line = app(EnrichmentUsageService::class)->record(['input_tokens' => 10, 'output_tokens' => 5, 'model' => null]);

        $this->assertSame('10 in / 5 out tokens, n/a (unknown model)', $line);
    }

    private function seedPricing(string $model, float $input, float $output): void
    {
        DB::connection('intelligence')->table('model_pricing')->insert([
            'provider' => 'google',
            'model' => $model,
            'input_per_million' => $input,
            'output_per_million' => $output,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'source' => 'manual',
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
