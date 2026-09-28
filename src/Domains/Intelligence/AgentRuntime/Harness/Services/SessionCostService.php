<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Services;

use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessUsage;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Services\ModelPricingCalculator;

/**
 * Kanvas prices the run itself. A harness talking to a custom (openai-compatible) provider reports
 * `cost: 0` and leaves its own session totals at zero, so trusting its numbers means a coding agent
 * that always appears free.
 */
class SessionCostService
{
    private const float DEFAULT_CAP_USD = 5.0;

    public function costFor(AgentTaskSession $session, HarnessUsage $usage): float
    {
        return app(ModelPricingCalculator::class)->costFor(
            $session->provider,
            $session->model,
            $usage->inputTokens,
            $usage->outputTokens,
            $usage->cacheReadTokens,
            $usage->cacheWriteTokens,
        );
    }

    /**
     * Null means uncapped, which is deliberately expressible — but the default is a cap, because an
     * alert about a runaway loop arrives after the money is gone.
     */
    public function capFor(AgentTaskSession $session): ?float
    {
        $company = $session->company;
        $app = $session->app;

        $configured = $company?->get(ConfigurationEnum::MAX_SESSION_COST_USD->value)
            ?? $app?->get(ConfigurationEnum::MAX_SESSION_COST_USD->value);

        if ($configured === null) {
            return self::DEFAULT_CAP_USD;
        }

        $cap = (float) $configured;

        return $cap > 0 ? $cap : null;
    }
}
