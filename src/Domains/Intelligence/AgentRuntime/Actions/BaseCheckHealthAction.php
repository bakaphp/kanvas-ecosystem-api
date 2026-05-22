<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentRuntimeStateEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\AgentRuntime\Services\AgentAwakeStateWriter;
use Kanvas\Intelligence\Agents\Enums\AgentAwakeStateEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;

/**
 * 2-strike state machine: first failed probe is a tolerated blip, second consecutive failure
 * flips OFFLINE, first OK after OFFLINE flips back to AWAKE. SLEEPING and UNSUPPORTED short-
 * circuit. Subclasses override only `probe()`.
 */
abstract class BaseCheckHealthAction
{
    public function __construct(
        protected readonly AgentDeployment $deployment,
    ) {
    }

    abstract protected function probe(Agent $agent): HealthCheckResultEnum;

    public function execute(): HealthCheckResultEnum
    {
        /** @var Apps $app */
        $app = Apps::getById($this->deployment->apps_id);
        $company = Companies::getById($this->deployment->companies_id);
        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp(
            $this->deployment->agent_id,
            $company,
            $app,
        );

        $current = $this->probe($agent);
        if ($current === HealthCheckResultEnum::UNSUPPORTED) {
            return $current;
        }

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

    // Writer no-ops if awake_state already matches the target or if the agent is SLEEPING,
    // so we don't recheck either here. State machine rule: 2-strike for FAILED → OFFLINE,
    // first OK → AWAKE.
    private function applyStateMachine(
        Agent $agent,
        AppInterface $app,
        CompanyInterface $company,
        HealthCheckResultEnum $current,
        HealthCheckResultEnum $previous,
    ): void {
        $writer = new AgentAwakeStateWriter();

        if ($current === HealthCheckResultEnum::OK) {
            $writer->write(
                $agent,
                $app,
                $company,
                AgentAwakeStateEnum::AWAKE,
                EventStatusEnum::SUCCESS,
                $this->deployment,
            );

            return;
        }

        if ($previous === HealthCheckResultEnum::FAILED) {
            $writer->write(
                $agent,
                $app,
                $company,
                AgentAwakeStateEnum::OFFLINE,
                EventStatusEnum::ERROR,
                $this->deployment,
            );
        }
    }
}
