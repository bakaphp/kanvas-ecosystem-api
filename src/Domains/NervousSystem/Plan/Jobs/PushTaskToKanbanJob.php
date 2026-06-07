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
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Support\KanbanStatusMapper;
use Throwable;

/**
 * Push a Kanvas Task to the agent's Hermes board as a child of its plan's root task. Ordering guard:
 * if the parent plan has no root task id yet, push the plan first and bail — the child lands on the
 * next run once the root exists. Status changes are gated by the runtime state machine.
 */
final class PushTaskToKanbanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Task $task)
    {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        try {
            $plan = $this->task->plan;

            if ($plan === null) {
                return;
            }

            $agent = $this->task->agent ?? $plan->agent;

            if ($agent === null) {
                return;
            }

            $deployment = $agent->activeDeployment;

            if (! $deployment instanceof AgentDeployment
                || ! $deployment->isRunning()
                || AgentProviderEnum::forDeployment($deployment) !== AgentProviderEnum::HERMES
            ) {
                return;
            }

            $this->overwriteAppService($plan->app);

            $parentExternalId = $plan->get(KanbanCustomFieldEnum::TASK_ID->value);

            // Root must exist before its child — push the plan first, retry the child next tick.
            if (! is_string($parentExternalId) || $parentExternalId === '') {
                PushPlanToKanbanJob::dispatch($plan);

                return;
            }

            $app = $plan->app;
            $company = $plan->company;
            $provider = AgentRuntimeProviderFactory::forDeployment($deployment);
            $externalId = $this->task->get(KanbanCustomFieldEnum::TASK_ID->value);

            if (! is_string($externalId) || $externalId === '') {
                $created = $provider->createKanbanTask(
                    $deployment,
                    $app,
                    $company,
                    new KanbanTaskInput(
                        title: $this->task->title,
                        body: $this->task->description,
                        idempotencyKey: $this->task->uuid,
                        parentIds: [$parentExternalId],
                    ),
                );

                $this->task->set(KanbanCustomFieldEnum::TASK_ID->value, $created->id);
                $this->task->set(KanbanCustomFieldEnum::STATUS->value, $created->status->value);
                $this->task->set(KanbanCustomFieldEnum::DEPLOYMENT_ID->value, (string) $deployment->getId());
                $this->task->set(KanbanCustomFieldEnum::SYNCED_AT->value, Carbon::now()->toIso8601String());

                return;
            }

            $verb = KanbanStatusMapper::taskStatusToTransition(TaskStatusEnum::from($this->task->status));

            if ($verb === null) {
                return;
            }

            $current = KanbanStatusEnum::fromRuntime(
                is_string($this->task->get(KanbanCustomFieldEnum::STATUS->value))
                    ? (string) $this->task->get(KanbanCustomFieldEnum::STATUS->value)
                    : null,
            );

            if (! KanbanStatusMapper::canTransition($current, $verb)) {
                return;
            }

            $result = $provider->transitionKanbanTask($deployment, $app, $company, $externalId, $verb);

            $this->task->set(KanbanCustomFieldEnum::STATUS->value, $result->status->value);
            $this->task->set(KanbanCustomFieldEnum::SYNCED_AT->value, Carbon::now()->toIso8601String());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
