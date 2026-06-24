<?php

declare(strict_types=1);

namespace Tests\Intelligence\Services;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Services\ModelPricingCalculator;
use Tests\TestCase;

class ModelPricingCalculatorTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private function seedPricing(): void
    {
        DB::connection('intelligence')->table('model_pricing')->insert([
            'provider' => 'anthropic',
            'model' => 'claude-test-model',
            'input_per_million' => 3.0,
            'output_per_million' => 15.0,
            'cache_read_per_million' => 0.3,
            'cache_write_per_million' => 3.75,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'source' => 'manual',
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function testComputesCostFromPricingTable(): void
    {
        $this->seedPricing();

        $cost = app(ModelPricingCalculator::class)->costFor(
            'anthropic',
            'claude-test-model',
            1_000_000,
            1_000_000,
            1_000_000,
            1_000_000,
            Carbon::create(2026, 6, 1),
        );

        // 3 + 15 + 0.3 + 3.75
        $this->assertEqualsWithDelta(22.05, $cost, 0.0001);
    }

    public function testFallsBackToModelOnlyWhenProviderDoesNotMatch(): void
    {
        // model_pricing stores LiteLLM provider naming (vertex_ai-language-models),
        // but the collector infers "google" from the model name. The lookup must
        // still find the row by model rather than returning $0.
        DB::connection('intelligence')->table('model_pricing')->insert([
            'provider' => 'vertex_ai-language-models',
            'model' => 'gemini-mismatch-test',
            'input_per_million' => 2.0,
            'output_per_million' => 12.0,
            'cache_read_per_million' => null,
            'cache_write_per_million' => null,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'source' => 'manual',
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $cost = app(ModelPricingCalculator::class)->costFor(
            'google',
            'gemini-mismatch-test',
            1_000_000,
            1_000_000,
            0,
            0,
            Carbon::create(2026, 6, 7),
        );

        // 1M * $2/M + 1M * $12/M = $14
        $this->assertEqualsWithDelta(14.0, $cost, 0.0001);
    }

    public function testReturnsZeroWhenModelOrProviderMissing(): void
    {
        $calc = app(ModelPricingCalculator::class);

        $this->assertSame(0.0, $calc->costFor(null, 'x', 100, 100));
        $this->assertSame(0.0, $calc->costFor('anthropic', null, 100, 100));
        $this->assertSame(0.0, $calc->costFor('', '', 100, 100));
    }

    public function testReturnsZeroWhenNoPricingRowMatches(): void
    {
        $cost = app(ModelPricingCalculator::class)->costFor(
            'nonexistent',
            'no-such-model',
            5_000_000,
            5_000_000,
        );

        $this->assertSame(0.0, $cost);
    }

    public function testIgnoresPricingNotYetEffective(): void
    {
        DB::connection('intelligence')->table('model_pricing')->insert([
            'provider' => 'openai',
            'model' => 'gpt-future',
            'input_per_million' => 10.0,
            'output_per_million' => 30.0,
            'cache_read_per_million' => null,
            'cache_write_per_million' => null,
            'effective_from' => '2026-12-01',
            'effective_until' => null,
            'source' => 'manual',
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $cost = app(ModelPricingCalculator::class)->costFor(
            'openai',
            'gpt-future',
            1_000_000,
            0,
            0,
            0,
            Carbon::create(2026, 6, 1),
        );

        $this->assertSame(0.0, $cost, 'pricing effective in the future must not apply to a June snapshot');
    }
}
