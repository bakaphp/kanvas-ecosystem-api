<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Middleware\BoundToolResultsMiddleware;
use Throwable;

/**
 * How many tokens of conversation history an agent may replay, sized to the model that will answer
 * and bounded by the `kanvas.agents.max_history_tokens` cost cap.
 *
 * A single flat window has to be safe for the smallest context we run, so every agent inherited a
 * 200K-model budget — including ones on Gemini 2.5 Pro, five times larger. This reads the model's real
 * input ceiling from the `model_pricing` catalogue and spends what is left after the two other claims
 * on the same request: the turn's tool output and the system prompt.
 */
class ModelContextWindowService
{
    /**
     * The legacy flat window, kept as the floor: it is what every history used before the ceiling was
     * read per model, so a model the catalogue does not know behaves exactly as it does today.
     */
    public const int MIN_HISTORY_TOKENS = 50_000;

    /**
     * Assumed ceiling when the catalogue has no row — the smallest context we run, which is what the
     * flat budget was sized against.
     */
    private const int ASSUMED_INPUT_TOKENS = 200_000;

    /**
     * The system prompt, the temporal/platform context blocks, and the JSON schemas of up to ~90 tools,
     * none of which the history trimmer can see or shrink.
     */
    private const int PROMPT_RESERVE_TOKENS = 30_000;

    /**
     * What a token costs in characters for the content agents actually carry — code, diffs and JSON.
     * NeuronAI's TokenCounter assumes 4, which is right for prose and optimistic for everything here.
     */
    private const float CHARS_PER_TOKEN = 3.0;

    /**
     * How far NeuronAI's estimate runs under the truth on that content (4 / 3, rounded up). The budget
     * is divided by it so an under-count cannot walk the request past the provider's ceiling.
     */
    private const float ESTIMATE_OPTIMISM = 1.35;

    private const int CACHE_TTL_SECONDS = 21_600;

    /**
     * The catalogue reports a model's largest possible window, which is not always the one our requests
     * are entitled to. Anthropic's 1M context is an opt-in beta gated behind
     * `anthropic-beta: context-1m-2025-08-07`, and nothing here sends it — so `claude-sonnet-4-5`
     * answers at 200K even though LiteLLM lists 1,000,000. Budgeting the catalogue figure would put the
     * request straight back over the provider's limit, which is the failure this class exists to stop.
     *
     * Gemini needs no entry: 1,048,576 is its standard window, and the provider named that exact number
     * back to us in the 400 (KANVAS-ECOSYSTEM-6F1).
     *
     * Raise or remove an entry only once the provider is actually sending whatever unlocks the larger
     * window.
     */
    private const array PROVIDER_CEILING_CAP = [
        AgentLlmProviderEnum::ANTHROPIC->value => 200_000,
    ];

    public static function forAgent(?Agent $agent): int
    {
        if ($agent === null) {
            return self::MIN_HISTORY_TOKENS;
        }

        try {
            return self::forModel(
                AgentProviderService::resolveProviderEnum($agent)->value,
                AgentProviderService::resolveModel($agent),
            );
        } catch (Throwable) {
            // Provider resolution needs app configuration that an unconfigured agent may not have.
            // A history that loads is worth more here than one that knows the exact ceiling.
            return self::MIN_HISTORY_TOKENS;
        }
    }

    public static function forModel(string $provider, string $model): int
    {
        $ceiling = self::maxInputTokens($provider, $model) ?? self::ASSUMED_INPUT_TOKENS;

        if (isset(self::PROVIDER_CEILING_CAP[$provider])) {
            $ceiling = min($ceiling, self::PROVIDER_CEILING_CAP[$provider]);
        }

        $reserve = self::toolOutputReserveTokens() + self::PROMPT_RESERVE_TOKENS;
        $budget = (int) (($ceiling - $reserve) / self::ESTIMATE_OPTIMISM);

        return min(max(self::MIN_HISTORY_TOKENS, $budget), self::costCapTokens());
    }

    /**
     * The model ceiling is what a request may hold, not what it should cost. Sizing Gemini to ~655K
     * took the September Gemini spend from ~$600/day to ~$1,500/day overnight, because the history
     * rides along on every tool-loop step — so the cap wins over both the ceiling and the floor.
     */
    private static function costCapTokens(): int
    {
        return max(1, (int) config('kanvas.agents.max_history_tokens', self::MIN_HISTORY_TOKENS));
    }

    /**
     * Tool output is bounded per turn in characters, and that allowance is spent before the history is
     * trimmed — so it is claimed against the ceiling whether or not the turn uses it.
     */
    private static function toolOutputReserveTokens(): int
    {
        return (int) ceil(BoundToolResultsMiddleware::MAX_CHARS_PER_TURN / self::CHARS_PER_TOKEN);
    }

    /**
     * `model_pricing` is a platform-wide catalogue with no tenant columns, so the cache key is global
     * on purpose — two apps on the same model share the same ceiling.
     *
     * Unknown is cached as 0, not null: Cache::remember() cannot tell a cached null from a miss, so
     * returning it would re-run this query on every turn of every agent whose model is not in the
     * catalogue — self-hosted models and any mistyped name, which is the bulk of them.
     */
    private static function maxInputTokens(string $provider, string $model): ?int
    {
        $ceiling = (int) Cache::remember(
            'model-context-window:' . $provider . ':' . $model,
            self::CACHE_TTL_SECONDS,
            static function () use ($provider, $model): int {
                $row = DB::connection('intelligence')
                    ->table('model_pricing')
                    ->where('provider', $provider)
                    ->where('model', $model)
                    ->whereNull('effective_until')
                    ->where('is_deleted', 0)
                    ->orderByDesc('effective_from')
                    ->first(['max_input_tokens']);

                return max(0, (int) ($row->max_input_tokens ?? 0));
            },
        );

        return $ceiling > 0 ? $ceiling : null;
    }
}
