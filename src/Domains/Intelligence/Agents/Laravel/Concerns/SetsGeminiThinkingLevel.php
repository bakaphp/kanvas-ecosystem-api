<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Concerns;

use Laravel\Ai\Enums\Lab;
use Override;

/**
 * laravel/ai talks to Gemini through the Interactions API, which has no `thinkingBudget`: a budget of 0
 * is rejected as an unknown parameter. The only switch it exposes is `thinking_level`, and what a model
 * accepts differs. Thought tokens bill as output, so on gemini-2.5-flash they are most of the cost of a
 * one-shot structured call (measured: 1,904 thought tokens for a 3-token answer).
 */
trait SetsGeminiThinkingLevel
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== Lab::Gemini && $provider !== Lab::Gemini->value) {
            return [];
        }

        $level = self::thinkingLevelFor((string) $this->getModel());

        return $level === null ? [] : ['generation_config' => ['thinking_level' => $level]];
    }

    /**
     * Flash-Lite does not think and answers 400 to any level; Gemini 3 models take `minimal`; the
     * 2.5 models reject `minimal`, and `low` is the cheapest level they accept.
     */
    private static function thinkingLevelFor(string $model): ?string
    {
        return match (true) {
            str_contains($model, 'flash-lite') => null,
            str_starts_with($model, 'gemini-3') => 'minimal',
            default => 'low',
        };
    }
}
