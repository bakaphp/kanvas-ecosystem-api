<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\ModelPricing;

/**
 * Turns a token count into a USD cost using the versioned model_pricing table,
 * picking the rate that was in force on the snapshot's date. Used by every
 * collector that doesn't get a cost figure straight from the runtime (OpenClaw,
 * the in-process Neuron/Laravel rollup). Hermes reports its own cost and skips
 * this entirely.
 */
class ModelPricingCalculator
{
    public function costFor(
        ?string $provider,
        ?string $model,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheWriteTokens = 0,
        ?Carbon $on = null,
    ): float {
        if ($model === null || $model === '') {
            return 0.0;
        }

        // Match on model first. The inferred provider (e.g. "google" for gemini)
        // rarely matches model_pricing's LiteLLM provider naming (e.g.
        // "vertex_ai-language-models"), so prefer a provider+model row when one
        // exists but fall back to model-only rather than returning $0.
        /** @var Builder $base */
        $base = ModelPricing::query()
            ->where('model', $model)
            ->activeOn($on ?? Carbon::now())
            ->where('is_deleted', 0);

        $pricing = null;
        if ($provider !== null && $provider !== '') {
            $pricing = (clone $base)
                ->where('provider', $provider)
                ->orderByDesc('effective_from')
                ->first();
        }

        $pricing ??= $base->orderByDesc('effective_from')->first();

        if ($pricing === null) {
            return 0.0;
        }

        return (float) $inputTokens / 1_000_000.0 * (float) $pricing->input_per_million
            + (float) $outputTokens / 1_000_000.0 * (float) $pricing->output_per_million
            + (float) $cacheReadTokens / 1_000_000.0 * (float) ($pricing->cache_read_per_million ?? 0)
            + (float) $cacheWriteTokens / 1_000_000.0 * (float) ($pricing->cache_write_per_million ?? 0);
    }

    /**
     * Best-effort LLM provider (anthropic/google/openai/...) from a model name —
     * the canonical home, shared by the deployment collectors and the local rollup.
     * Used for the snapshot's provider column and as the model_pricing lookup hint.
     */
    public static function inferProvider(string $model): ?string
    {
        if ($model === '') {
            return null;
        }

        if (str_contains($model, '/')) {
            return explode('/', $model)[0];
        }

        return match (true) {
            str_starts_with($model, 'gemini') => 'google',
            str_starts_with($model, 'claude') => 'anthropic',
            str_starts_with($model, 'gpt'), str_starts_with($model, 'o1'), str_starts_with($model, 'o3') => 'openai',
            str_starts_with($model, 'llama') => 'meta',
            str_starts_with($model, 'mistral') => 'mistral',
            default => null,
        };
    }
}
