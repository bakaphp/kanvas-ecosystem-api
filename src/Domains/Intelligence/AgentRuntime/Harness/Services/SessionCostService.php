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
    private const float DEFAULT_CAP_USD = 10.0;
    private const int DEFAULT_MAX_ACTIVE_MINUTES = 180;

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
        $cap = (float) ($this->setting($session, ConfigurationEnum::MAX_SESSION_COST_USD) ?? self::DEFAULT_CAP_USD);

        return $cap > 0 ? $cap * $this->blocks($session) : null;
    }

    /**
     * Minutes of work, not wall clock: time parked on a person's answer is not counted, or a question
     * asked at minute 30 and answered an hour later would come back to a session already killed. The
     * cost cap is what bounds spend; this only catches a run that is busy without ever finishing.
     */
    public function maxActiveMinutesFor(AgentTaskSession $session): int
    {
        $minutes = (int) ($this->setting($session, ConfigurationEnum::MAX_SESSION_MINUTES) ?? 0);

        return ($minutes > 0 ? $minutes : self::DEFAULT_MAX_ACTIVE_MINUTES) * $this->blocks($session);
    }

    private function blocks(AgentTaskSession $session): int
    {
        return 1 + (int) $session->limit_extensions;
    }

    private function setting(AgentTaskSession $session, ConfigurationEnum $key): mixed
    {
        return $session->company?->get($key->value) ?? $session->app?->get($key->value);
    }
}
