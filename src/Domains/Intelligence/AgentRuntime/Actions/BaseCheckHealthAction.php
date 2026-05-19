<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentRuntimeStateEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Enums\AgentAwakeStateEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;

/**
 * Runtime-agnostic 2-strike health-check state machine. Concrete subclasses override only
 * `probe()`; everything that mutates the agent or emits the ledger event lives here so the
 * rules can't drift between runtimes.
 *
 * State transitions:
 *   - First failed probe: record 'failed' on the agent custom field; awake_state untouched.
 *   - Second consecutive failed probe with awake_state != OFFLINE: flip to OFFLINE + emit
 *     `agent.runtime.health.observed` (status=error).
 *   - First successful probe while OFFLINE: flip to AWAKE + emit (status=success).
 *   - SLEEPING is never touched — owned by the cycle compiler. UNSUPPORTED short-circuits.
 */
abstract class BaseCheckHealthAction
{
    public function __construct(
        protected readonly AgentDeployment $deployment,
    ) {
    }

    abstract protected function probe(): HealthCheckResultEnum;

    public function execute(): HealthCheckResultEnum
    {
        $current = $this->probe();
        if ($current === HealthCheckResultEnum::UNSUPPORTED) {
            return $current;
        }

        /** @var Apps $app */
        $app = Apps::getById($this->deployment->apps_id);
        $company = Companies::getById($this->deployment->companies_id);
        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp(
            $this->deployment->agent_id,
            $company,
            $app,
        );

        $previous = $this->resolvePreviousStatus($agent);
        $agent->set(AgentRuntimeStateEnum::LAST_HEALTH_STATUS->value, $current->value);

        $this->deployment->last_health_check = now();
        $this->deployment->saveOrFail();

        $this->applyStateMachine(
            $agent,
            $app,
            $company,
            $current,
            $previous,
        );

        return $current;
    }

    private function resolvePreviousStatus(Agent $agent): HealthCheckResultEnum
    {
        $raw = (string) ($agent->get(AgentRuntimeStateEnum::LAST_HEALTH_STATUS->value) ?? '');

        return $raw === HealthCheckResultEnum::FAILED->value
            ? HealthCheckResultEnum::FAILED
            : HealthCheckResultEnum::OK;
    }

    private function applyStateMachine(
        Agent $agent,
        AppInterface $app,
        CompanyInterface $company,
        HealthCheckResultEnum $current,
        HealthCheckResultEnum $previous,
    ): void {
        if ($agent->awake_state === AgentAwakeStateEnum::SLEEPING->value) {
            return;
        }

        if ($current === HealthCheckResultEnum::OK && $agent->awake_state === AgentAwakeStateEnum::OFFLINE->value) {
            $this->flipAwakeState($agent, AgentAwakeStateEnum::AWAKE);
            $this->emit(
                $app,
                $company,
                $agent,
                EventStatusEnum::SUCCESS,
                AgentAwakeStateEnum::AWAKE,
            );

            return;
        }

        $shouldGoOffline = $current === HealthCheckResultEnum::FAILED
            && $previous === HealthCheckResultEnum::FAILED
            && $agent->awake_state !== AgentAwakeStateEnum::OFFLINE->value;

        if ($shouldGoOffline) {
            $this->flipAwakeState($agent, AgentAwakeStateEnum::OFFLINE);
            $this->emit(
                $app,
                $company,
                $agent,
                EventStatusEnum::ERROR,
                AgentAwakeStateEnum::OFFLINE,
            );
        }
    }

    private function flipAwakeState(Agent $agent, AgentAwakeStateEnum $newState): void
    {
        $agent->awake_state = $newState->value;
        $agent->last_state_changed_at = now();
        $agent->saveOrFail();
    }

    private function emit(
        AppInterface $app,
        CompanyInterface $company,
        Agent $agent,
        EventStatusEnum $status,
        AgentAwakeStateEnum $newAwakeState,
    ): void {
        new AppendEventAction(
            new EventData(
                app: $app,
                company: $company,
                sourceDomain: 'Intelligence.AgentRuntime',
                eventType: 'agent.runtime.health.observed',
                status: $status,
                sourceEntityType: Agent::class,
                sourceEntityId: $agent->getId(),
                actorType: 'System',
                payload: [
                    'awake_state' => $newAwakeState->value,
                    'provider' => $this->deployment->provider,
                    'deployment_id' => $this->deployment->getId(),
                    'container_name' => $this->deployment->container_name,
                ],
            ),
        )->execute();
    }
}
