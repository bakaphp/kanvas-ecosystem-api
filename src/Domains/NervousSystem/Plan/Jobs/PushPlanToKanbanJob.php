<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTaskInput;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\KanbanStatusMapper;
use Throwable;

/**
 * Push a Kanvas Plan to the agent's Hermes board as a root task. Create is idempotent
 * (idempotency_key = plan uuid); status changes go through the runtime's lifecycle verbs, gated by
 * the task's current runtime status (an illegal transition is skipped, not forced). Updates the
 * stored AGENT_KANBAN_STATUS to the value just sent so the next ingest doesn't echo it back.
 */
final class PushPlanToKanbanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Plan $plan)
    {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        try {
            $agent = $this->plan->agent;

            if ($agent === null) {
                return;
            }

            $deployment = $agent->activeDeployment;

            if (! $deployment instanceof AgentDeployment
                || ! $deployment->isRunning()
                || ! AgentProviderEnum::forDeployment($deployment)->isHermes()
            ) {
                return;
            }

            $this->overwriteAppService($this->plan->app);

            $app = $this->plan->app;
            $company = $this->plan->company;
            $provider = AgentRuntimeProviderFactory::forDeployment($deployment);
            $externalId = $this->plan->get(KanbanCustomFieldEnum::TASK_ID->value);

            if (! is_string($externalId) || $externalId === '') {
                $created = $provider->createKanbanTask(
                    $deployment,
                    $app,
                    $company,
                    new KanbanTaskInput(
                        title: $this->plan->title,
                        body: $this->plan->description,
                        idempotencyKey: $this->plan->uuid,
                    ),
                );

                $this->plan->set(KanbanCustomFieldEnum::TASK_ID->value, $created->id);
                $this->plan->set(KanbanCustomFieldEnum::STATUS->value, $created->status->value);
                $this->plan->set(KanbanCustomFieldEnum::DEPLOYMENT_ID->value, (string) $deployment->getId());
                $this->plan->set(KanbanCustomFieldEnum::SYNCED_AT->value, Carbon::now()->toIso8601String());

                return;
            }

            $verb = KanbanStatusMapper::planStatusToTransition(PlanStatusEnum::from($this->plan->status));

            if ($verb === null) {
                return;
            }

            $current = KanbanStatusEnum::fromRuntime(
                is_string($this->plan->get(KanbanCustomFieldEnum::STATUS->value))
                    ? (string) $this->plan->get(KanbanCustomFieldEnum::STATUS->value)
                    : null,
            );

            if (! KanbanStatusMapper::canTransition($current, $verb)) {
                return;
            }

            $result = $provider->transitionKanbanTask($deployment, $app, $company, $externalId, $verb);

            $this->plan->set(KanbanCustomFieldEnum::STATUS->value, $result->status->value);
            $this->plan->set(KanbanCustomFieldEnum::SYNCED_AT->value, Carbon::now()->toIso8601String());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
