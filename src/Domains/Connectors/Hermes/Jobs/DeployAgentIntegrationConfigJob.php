<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Hermes\Actions\DeployGoogleCredentialsAction;
use Kanvas\Intelligence\Agents\Enums\AgentIntegrationConfigKeyEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

class DeployAgentIntegrationConfigJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    /**
     * @param array<int, string> $changedKeys keys from AgentIntegrationConfigKeyEnum::value
     */
    public function __construct(
        protected Agent $agent,
        protected array $changedKeys,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        foreach ($this->changedKeys as $key) {
            try {
                $this->deployKey($key);
            } catch (Throwable $e) {
                report($e);
                Log::warning('Failed to deploy agent integration', [
                    'agent_id' => $this->agent->getId(),
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function deployKey(string $key): void
    {
        match ($key) {
            AgentIntegrationConfigKeyEnum::GOOGLE->value
                => new DeployGoogleCredentialsAction($this->agent)->execute(),
            // Other integrations (Jira, ...) deploy on their own path when added.
            default => null,
        };
    }
}
