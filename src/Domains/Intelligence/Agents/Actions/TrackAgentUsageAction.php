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
        protected int $threadWaitMs = 0,
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

        $metrics = [
            'duration_ms' => $this->durationMs,
            'input_chars' => mb_strlen($this->message),
            'output_chars' => mb_strlen($this->response),
        ];
        if ($this->threadWaitMs > 0) {
            $metrics['thread_wait_ms'] = $this->threadWaitMs;
        }

        foreach ($metrics as $type => $value) {
            AgentPerformanceMetric::create([
                'apps_id' => $this->app->getId(),
                'agent_id' => $this->agent->getId(),
                'agent_history_id' => $history->getId(),
                'metric_type' => $type,
                'value' => $value,
                'period_start' => $now,
                'period_end' => $now,
            ]);
        }

        return $history;
    }
}
