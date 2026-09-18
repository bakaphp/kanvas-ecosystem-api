<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Actions\SyncModelPricingAction;
use Kanvas\Intelligence\Agents\Services\ModelContextWindowService;
use Tests\TestCase;

/**
 * A single flat history window has to be safe for the smallest context we run, so every agent
 * inherited a 200K-model budget — a Gemini 2.5 Pro agent was held to 5% of its model. These pin the
 * sizing: wide models get most of their ceiling, narrow and unknown ones keep the legacy floor, and
 * the budget always leaves the tool-output allowance room to be spent.
 */
class ModelContextWindowServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function catalogue(string $provider, string $model, ?int $maxInputTokens): void
    {
        DB::connection('intelligence')->table('model_pricing')->insert([
            'provider' => $provider,
            'model' => $model,
            'input_per_million' => 1.25,
            'output_per_million' => 10.0,
            'max_input_tokens' => $maxInputTokens,
            'effective_from' => Carbon::now()->toDateString(),
            'source' => 'injected',
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function testAWideModelGetsMostOfItsCeilingNotTheLegacyFloor(): void
    {
        $this->catalogue('gemini', 'gemini-2.5-pro-ctxtest', 1_048_576);

        $window = ModelContextWindowService::forModel('gemini', 'gemini-2.5-pro-ctxtest');

        $this->assertGreaterThan(500_000, $window);
        $this->assertLessThan(1_048_576, $window, 'The budget must leave the provider headroom.');
    }

    public function testANarrowModelKeepsTheLegacyFloor(): void
    {
        $this->catalogue('anthropic', 'claude-narrow-ctxtest', 200_000);

        $this->assertSame(
            ModelContextWindowService::MIN_HISTORY_TOKENS,
            ModelContextWindowService::forModel('anthropic', 'claude-narrow-ctxtest'),
        );
    }

    public function testAnUnknownModelKeepsTheLegacyFloor(): void
    {
        $this->assertSame(
            ModelContextWindowService::MIN_HISTORY_TOKENS,
            ModelContextWindowService::forModel('openai', 'not-in-the-catalogue-ctxtest'),
        );
    }

    /**
     * A row with a NULL window (every row written before the column existed) must read as unknown, not
     * as a zero ceiling — otherwise the whole back catalogue would size to a negative budget.
     */
    public function testANullCeilingReadsAsUnknown(): void
    {
        $this->catalogue('openai', 'legacy-row-ctxtest', null);

        $this->assertSame(
            ModelContextWindowService::MIN_HISTORY_TOKENS,
            ModelContextWindowService::forModel('openai', 'legacy-row-ctxtest'),
        );
    }

    /**
     * LiteLLM lists Sonnet at 1,000,000 because that window exists — behind an `anthropic-beta` header
     * nothing here sends. Budgeting the catalogue figure would hand a 200K model a 600K history and put
     * the request straight back over the limit.
     */
    public function testAnAnthropicBetaOnlyCeilingIsCappedToWhatWeCanActuallySend(): void
    {
        $this->catalogue('anthropic', 'claude-beta-window-ctxtest', 1_000_000);

        $this->assertSame(
            ModelContextWindowService::MIN_HISTORY_TOKENS,
            ModelContextWindowService::forModel('anthropic', 'claude-beta-window-ctxtest'),
        );
    }

    /**
     * The same figure on Gemini is its standard window — the provider named 1,048,576 back to us in the
     * 400 — so it must not be capped.
     */
    public function testTheSameCeilingIsNotCappedOnGemini(): void
    {
        $this->catalogue('gemini', 'gemini-uncapped-ctxtest', 1_000_000);

        $this->assertGreaterThan(500_000, ModelContextWindowService::forModel('gemini', 'gemini-uncapped-ctxtest'));
    }

    public function testTheBudgetLeavesRoomForAFullTurnOfToolOutput(): void
    {
        $this->catalogue('gemini', 'gemini-budget-ctxtest', 1_048_576);

        $window = ModelContextWindowService::forModel('gemini', 'gemini-budget-ctxtest');

        // The tool-output allowance is spent in characters before the history is trimmed, so the two
        // together — at the pessimistic 3 chars/token the guard is sized for — must still fit.
        $toolTokens = (int) ceil(400_000 / 3);

        $this->assertLessThan(1_048_576, $window + $toolTokens);
    }

    /**
     * Cache::remember() cannot tell a cached null from a miss, so an unknown model must cache a
     * sentinel or its lookup re-runs on every turn — the self-hosted and mistyped-model agents, which
     * are the bulk of them.
     */
    public function testAnUnknownModelIsLookedUpOnceNotOnEveryTurn(): void
    {
        DB::connection('intelligence')->enableQueryLog();
        DB::connection('intelligence')->flushQueryLog();

        ModelContextWindowService::forModel('openai', 'uncached-miss-ctxtest');
        ModelContextWindowService::forModel('openai', 'uncached-miss-ctxtest');
        ModelContextWindowService::forModel('openai', 'uncached-miss-ctxtest');

        $lookups = array_filter(
            DB::connection('intelligence')->getQueryLog(),
            static fn (array $q): bool => str_contains($q['query'], 'model_pricing'),
        );
        DB::connection('intelligence')->disableQueryLog();

        $this->assertCount(1, $lookups);
    }

    public function testTheSyncStoresTheUpstreamContextWindow(): void
    {
        $payload = json_encode([
            'gemini/gemini-sync-ctxtest' => [
                'litellm_provider' => 'gemini',
                'input_cost_per_token' => 0.00000125,
                'output_cost_per_token' => 0.00001,
                'max_input_tokens' => 1_048_576,
            ],
        ]);

        new SyncModelPricingAction()->execute($payload);

        $row = DB::connection('intelligence')->table('model_pricing')
            ->where('provider', 'gemini')
            ->where('model', 'gemini-sync-ctxtest')
            ->first();

        $this->assertSame(1_048_576, (int) $row->max_input_tokens);
    }

    /**
     * A provider can widen a model's window without changing its price. That is not a rate change, so
     * it must correct the live row rather than open a new priced period.
     */
    public function testAWidenedWindowUpdatesInPlaceWithoutVersioningThePrice(): void
    {
        $entry = [
            'litellm_provider' => 'gemini',
            'input_cost_per_token' => 0.00000125,
            'output_cost_per_token' => 0.00001,
            'max_input_tokens' => 32_000,
        ];
        new SyncModelPricingAction()->execute(json_encode(['gemini/gemini-widen-ctxtest' => $entry]));

        $entry['max_input_tokens'] = 1_048_576;
        new SyncModelPricingAction()->execute(json_encode(['gemini/gemini-widen-ctxtest' => $entry]));

        $rows = DB::connection('intelligence')->table('model_pricing')
            ->where('provider', 'gemini')
            ->where('model', 'gemini-widen-ctxtest')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(1_048_576, (int) $rows->first()->max_input_tokens);
        $this->assertNull($rows->first()->effective_until);
    }
}
