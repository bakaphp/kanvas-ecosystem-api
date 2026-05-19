<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentRuntimeStateEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;

/**
 * Runtime-agnostic health-check state machine.
 *
 * Concrete subclasses (e.g. `Hermes\Actions\CheckApiHealthAction`) override only `probe()` to
 * describe HOW that runtime reports liveness — HTTP /health for Hermes, `openclaw status` for
 * OpenClaw, whatever Nano grows. Everything that touches the agent record (last-check memory,
 * awake_state flip, ledger emission) lives here so the rules don't drift between runtimes.
 *
 * State machine:
 *   - First failed probe: record 'failed' on the agent custom field, awake_state untouched
 *     (transient blip — SSH reconnect, container restart).
 *   - Second consecutive failed probe with awake_state != 'offline': flip to 'offline' and
 *     emit `agent.runtime.health.observed` (status=error).
 *   - First successful probe while offline: flip to 'awake' and emit (status=success).
 *   - Sleeping agents are never touched — sleep is owned by the cycle compiler, and the runtime
 *     should still respond to its health probe during sleep.
 *   - UNSUPPORTED probe result: short-circuit before the state machine runs (no writes, no event).
 */
abstract class BaseCheckHealthAction
{
    private const string AWAKE_STATE_AWAKE = 'awake';
    private const string AWAKE_STATE_SLEEPING = 'sleeping';
    private const string AWAKE_STATE_OFFLINE = 'offline';

    public function __construct(
        protected readonly AgentDeployment $deployment,
    ) {
    }

    /**
     * Per-runtime liveness probe. Returns `OK` when the runtime confirms it's up, `FAILED` for
     * any error (timeout, non-200, SSH failure, missing credentials), `UNSUPPORTED` when this
     * runtime has no probe yet — the state machine treats UNSUPPORTED as "skip silently".
     */
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
        if ($agent->awake_state === self::AWAKE_STATE_SLEEPING) {
            return;
        }

        if ($current === HealthCheckResultEnum::OK && $agent->awake_state === self::AWAKE_STATE_OFFLINE) {
            $this->flipAwakeState($agent, self::AWAKE_STATE_AWAKE);
            $this->emit(
                $app,
                $company,
                $agent,
                EventStatusEnum::SUCCESS,
                self::AWAKE_STATE_AWAKE,
            );

            return;
        }

        $shouldGoOffline = $current === HealthCheckResultEnum::FAILED
            && $previous === HealthCheckResultEnum::FAILED
            && $agent->awake_state !== self::AWAKE_STATE_OFFLINE;

        if ($shouldGoOffline) {
            $this->flipAwakeState($agent, self::AWAKE_STATE_OFFLINE);
            $this->emit(
                $app,
                $company,
                $agent,
                EventStatusEnum::ERROR,
                self::AWAKE_STATE_OFFLINE,
            );
        }
    }

    private function flipAwakeState(Agent $agent, string $newState): void
    {
        $agent->awake_state = $newState;
        $agent->last_state_changed_at = now();
        $agent->saveOrFail();
    }

    private function emit(
        AppInterface $app,
        CompanyInterface $company,
        Agent $agent,
        EventStatusEnum $status,
        string $newAwakeState,
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
                    'awake_state' => $newAwakeState,
                    'provider' => $this->deployment->provider,
                    'deployment_id' => $this->deployment->getId(),
                    'container_name' => $this->deployment->container_name,
                ],
            ),
        )->execute();
    }
}
