<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Actions;

use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Services\OnboardingSectionsService;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Scribe\Setup\DataTransferObject\ReadinessItem;
use Kanvas\Scribe\Setup\Onboarding\AccountingOnboardingSetupHook;
use Kanvas\Scribe\Setup\Services\AccountingReadinessService;

/**
 * Closes the onboarding setup plan's tasks whose readiness requirement is now ready. A step names its
 * requirement in the catalog (`setup_steps[].readiness`); the task is matched by that step's title,
 * the only thing a plan task keeps of its step. Tasks already done, skipped or for a requirement that
 * is still open are left alone.
 */
class SyncAccountingSetupPlanAction
{
    public function __construct(
        private readonly CompanyOnboarding $row,
    ) {
    }

    /**
     * @return int tasks closed
     */
    public function execute(): int
    {
        $closed = 0;

        foreach ($this->openTasks() as ['task' => $task, 'requirement' => $requirement, 'item' => $item]) {
            if (! $item?->isReady()) {
                continue;
            }

            new UpdateTaskStatusAction(
                $task,
                TaskStatusEnum::DONE,
                result: ['closed_by' => 'accounting_readiness', 'requirement' => $requirement, 'detail' => $item->detail],
            )->execute();
            $closed++;
        }

        return $closed;
    }

    /**
     * The setup plan's tasks still open that a readiness requirement closes, each with that requirement's
     * current answer (null when the requirement does not apply to this company).
     *
     * @param array<string, ReadinessItem>|null $readiness an answer the caller already has
     * @return list<array{task: Task, requirement: string, item: ReadinessItem|null}>
     */
    public function openTasks(?array $readiness = null): array
    {
        $planId = (int) $this->row->dataGet('setup_plan_id', 0);
        if ($planId <= 0) {
            return [];
        }

        $app = $this->row->app;
        $company = $this->row->company;

        /** @var Plan|null $plan */
        $plan = Plan::query()->fromApp($app)->fromCompany($company)->whereKey($planId)->first();
        if ($plan === null) {
            return [];
        }

        $readinessByTitle = $this->readinessByTitle();
        if ($readinessByTitle === []) {
            return [];
        }

        $readiness ??= new AccountingReadinessService()->check($app, $company);
        $open = [];

        /** @var Task $task */
        foreach ($plan->tasks()->orderBy('sequence')->get() as $task) {
            $requirement = $readinessByTitle[$task->title] ?? null;
            $status = $task->status instanceof TaskStatusEnum ? $task->status : TaskStatusEnum::tryFrom((string) $task->status);
            if ($requirement === null || in_array($status, [TaskStatusEnum::DONE, TaskStatusEnum::SKIPPED], true)) {
                continue;
            }

            $open[] = ['task' => $task, 'requirement' => $requirement, 'item' => $readiness[$requirement] ?? null];
        }

        return $open;
    }

    /**
     * @return array<string, string> step title → readiness requirement, over every accounting section
     */
    private function readinessByTitle(): array
    {
        $map = [];
        foreach (new OnboardingSectionsService()->forApp($this->row->app) as $section) {
            $config = $section->config();
            if ($config->setup !== AccountingOnboardingSetupHook::KEY) {
                continue;
            }
            foreach ($config->setupStepDefinitions as $step) {
                if ($step['readiness'] !== null) {
                    $map[$step['title']] ??= $step['readiness'];
                }
            }
        }

        return $map;
    }
}
