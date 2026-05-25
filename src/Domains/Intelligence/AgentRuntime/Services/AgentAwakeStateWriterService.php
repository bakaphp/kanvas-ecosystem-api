<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\Agents\Enums\AgentAwakeStateEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;

/**
 * Single writer for `agents.awake_state` changes + the paired ledger event. No-op when already
 * at target or when SLEEPING (the cycle compiler owns the column during a cycle).
 */
class AgentAwakeStateWriterService
{
    public function write(
        Agent $agent,
        AppInterface $app,
        CompanyInterface $company,
        AgentAwakeStateEnum $newState,
        EventStatusEnum $eventStatus,
        ?AgentDeployment $deployment = null,
    ): bool {
        if ($agent->awake_state === $newState->value) {
            return false;
        }

        if ($agent->awake_state === AgentAwakeStateEnum::SLEEPING->value) {
            return false;
        }

        $agent->awake_state = $newState->value;
        $agent->last_state_changed_at = now();
        $agent->saveOrFail();

        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'Intelligence.AgentRuntime',
                eventType: 'agent.runtime.health.observed',
                status: $eventStatus,
                sourceEntityType: Agent::class,
                sourceEntityId: $agent->getId(),
                actorType: 'System',
                payload: [
                    'awake_state' => $newState->value,
                    'provider' => $deployment?->provider ?? $agent->type?->provider,
                    'deployment_id' => $deployment?->getId(),
                    'container_name' => $deployment?->container_name,
                ],
            ),
        )->execute();

        return true;
    }
}
