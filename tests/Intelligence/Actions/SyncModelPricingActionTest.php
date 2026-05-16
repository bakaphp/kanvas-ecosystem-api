<?php

declare(strict_types=1);

namespace Tests\Intelligence\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Actions\SyncModelPricingAction;
use Tests\TestCase;

class SyncModelPricingActionTest extends TestCase
{
    private const FIXTURE = <<<'JSON'
    {
      "claude-opus-4": {
        "litellm_provider": "anthropic",
        "input_cost_per_token": 0.000015,
        "output_cost_per_token": 0.000075,
        "cache_read_input_token_cost": 0.0000015,
        "cache_creation_input_token_cost": 0.00001875
      },
      "anthropic/claude-sonnet-4": {
        "litellm_provider": "anthropic",
        "input_cost_per_token": 0.000003,
        "output_cost_per_token": 0.000015
      },
      "gpt-5-mini": {
        "litellm_provider": "openai",
        "input_cost_per_token": 0.00000015,
        "output_cost_per_token": 0.0000006
      },
      "this-row-has-no-pricing": {
        "litellm_provider": "fake"
      }
    }
    JSON;

    private function purgeTestRows(): void
    {
        DB::connection('intelligence')->table('model_pricing')
            ->whereIn('model', ['claude-opus-4', 'claude-sonnet-4', 'gpt-5-mini', 'gpt-5-large'])
            ->whereIn('provider', ['anthropic', 'openai'])
            ->delete();
    }

    public function testFirstSyncInsertsOneRowPerUpstreamModel(): void
    {
        $this->purgeTestRows();

        $result = new SyncModelPricingAction()->execute(self::FIXTURE);

        $this->assertSame(3, $result['inserted'], 'Three usable rows in the fixture; the malformed one must be skipped');
        $this->assertSame(0, $result['versioned']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame('injected', $result['source']);

        $opus = DB::connection('intelligence')->table('model_pricing')
            ->where('provider', 'anthropic')->where('model', 'claude-opus-4')
            ->whereNull('effective_until')
            ->first();
        $this->assertNotNull($opus);
        $this->assertSame('15.0000', (string) $opus->input_per_million);
        $this->assertSame('75.0000', (string) $opus->output_per_million);
        $this->assertSame('1.5000', (string) $opus->cache_read_per_million);
        $this->assertSame('18.7500', (string) $opus->cache_write_per_million);

        $sonnet = DB::connection('intelligence')->table('model_pricing')
            ->where('provider', 'anthropic')->where('model', 'claude-sonnet-4')
            ->whereNull('effective_until')
            ->first();
        $this->assertNotNull($sonnet, 'Vendor-prefixed key "anthropic/claude-sonnet-4" must normalize to model="claude-sonnet-4"');
    }

    public function testSecondSyncWithUnchangedRatesIsAFullNoOp(): void
    {
        $this->purgeTestRows();
        new SyncModelPricingAction()->execute(self::FIXTURE);

        $result = new SyncModelPricingAction()->execute(self::FIXTURE);

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(0, $result['versioned']);
        $this->assertSame(3, $result['unchanged']);

        $rows = DB::connection('intelligence')->table('model_pricing')
            ->where('provider', 'anthropic')->where('model', 'claude-opus-4')
            ->count();
        $this->assertSame(1, $rows, 'Unchanged rate must not produce duplicate rows');
    }

    public function testRateChangeInsertsNewRowAndClosesPriorOne(): void
    {
        $this->purgeTestRows();
        new SyncModelPricingAction()->execute(self::FIXTURE);

        $bumped = str_replace('"input_cost_per_token": 0.000015', '"input_cost_per_token": 0.000020', self::FIXTURE);
        $result = new SyncModelPricingAction()->execute($bumped);

        $this->assertSame(1, $result['versioned'], 'Only claude-opus-4 rate changed');
        $this->assertSame(2, $result['unchanged']);
        $this->assertSame(0, $result['inserted']);

        // Two rows now for claude-opus-4 — the old one closed, the new one open.
        $rows = DB::connection('intelligence')->table('model_pricing')
            ->where('provider', 'anthropic')->where('model', 'claude-opus-4')
            ->orderBy('effective_from')
            ->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows[0]->effective_until, 'Prior row must be closed');
        $this->assertSame('15.0000', (string) $rows[0]->input_per_million);
        $this->assertNull($rows[1]->effective_until, 'New row must be open');
        $this->assertSame('20.0000', (string) $rows[1]->input_per_million);
    }

    public function testNewModelAppearingUpstreamGetsInserted(): void
    {
        $this->purgeTestRows();
        new SyncModelPricingAction()->execute(self::FIXTURE);

        $expanded = json_encode(array_merge(
            json_decode(self::FIXTURE, true),
            ['gpt-5-large' => [
                'litellm_provider' => 'openai',
                'input_cost_per_token' => 0.000005,
                'output_cost_per_token' => 0.000015,
            ]],
        ));

        $result = new SyncModelPricingAction()->execute((string) $expanded);

        $this->assertSame(1, $result['inserted'], 'gpt-5-large is new this run');
        $this->assertSame(3, $result['unchanged']);

        DB::connection('intelligence')->table('model_pricing')
            ->where('provider', 'openai')->where('model', 'gpt-5-large')
            ->delete();
    }

    public function testMalformedPayloadThrows(): void
    {
        $this->expectExceptionMessage('zero usable rows');
        new SyncModelPricingAction()->execute('{}');
    }
}
