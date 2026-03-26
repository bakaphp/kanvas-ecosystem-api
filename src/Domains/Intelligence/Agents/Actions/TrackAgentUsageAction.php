<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentHistory;
use Kanvas\Intelligence\Agents\Models\AgentPerformanceMetric;

class TrackAgentUsageAction
{
    public function __construct(
        protected Agent $agent,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected string $message,
        protected string $response,
        protected float $durationMs,
        protected ?string $sessionId = null,
        protected ?int $userId = null,
    ) {
    }

    public function execute(): AgentHistory
    {
        $history = AgentHistory::create([
            'agent_id' => $this->agent->getId(),
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->app->getId(),
            'users_id' => $this->userId,
            'entity_namespace' => Agent::class,
            'entity_id' => $this->agent->getId(),
            'context' => $this->sessionId ?? '',
            'input' => [
                'message' => $this->message,
                'session_id' => $this->sessionId,
            ],
            'output' => [
                'response' => $this->response,
            ],
        ]);

        $now = now();

        AgentPerformanceMetric::create([
            'apps_id' => $this->app->getId(),
            'agent_id' => $this->agent->getId(),
            'agent_history_id' => $history->getId(),
            'metric_type' => 'duration_ms',
            'value' => $this->durationMs,
            'period_start' => $now,
            'period_end' => $now,
        ]);

        AgentPerformanceMetric::create([
            'apps_id' => $this->app->getId(),
            'agent_id' => $this->agent->getId(),
            'agent_history_id' => $history->getId(),
            'metric_type' => 'input_chars',
            'value' => mb_strlen($this->message),
            'period_start' => $now,
            'period_end' => $now,
        ]);

        AgentPerformanceMetric::create([
            'apps_id' => $this->app->getId(),
            'agent_id' => $this->agent->getId(),
            'agent_history_id' => $history->getId(),
            'metric_type' => 'output_chars',
            'value' => mb_strlen($this->response),
            'period_start' => $now,
            'period_end' => $now,
        ]);

        return $history;
    }
}
