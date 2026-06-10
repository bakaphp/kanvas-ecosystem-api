<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\Actions\ApprovePlanAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\DeletePlanAction;
use Kanvas\NervousSystem\Plan\Actions\DeleteTaskAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;

class PlanMutation
{
    public function create(mixed $rootValue, array $request): Plan
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        return new CreatePlanAction(
            PlanData::fromMultiple($app, $user, $company, $input),
            tasks: TaskData::fromMultipleArray(plan: null, rows: $input['tasks'] ?? []),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Plan
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Plan $plan */
        $plan = Plan::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdatePlanAction(
            $plan,
            PlanData::forUpdate($plan, $app, $company, $request['input']),
        )->execute();
    }

    public function approve(mixed $rootValue, array $request): Plan
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Plan $plan */
        $plan = Plan::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new ApprovePlanAction(
            plan: $plan,
            reviewer: $user,
            approved: (bool) $input['approved'],
            reviewOutcome: $input['review_outcome'] ?? null,
        )->execute();
    }

    public function addTask(mixed $rootValue, array $request): Task
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Plan $plan */
        $plan = Plan::getByIdFromCompanyApp((int) $request['plan_id'], $company, $app);

        $input = $request['input'];

        $task = new AddTaskAction(
            $plan,
            TaskData::fromMultiple($plan, $input),
        )->execute();

        // Per-task assignee (the Task DTO carries no agent; set it post-create).
        if (isset($input['agent_id'])) {
            $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app);
            $task->agent_id = $agent->getId();
            $task->saveQuietly();
        }

        return $task;
    }

    public function updateTaskStatus(mixed $rootValue, array $request): Task
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Task $task */
        $task = Task::query()
            ->where('id', (int) $request['id'])
            ->fromApp($app)
            ->fromCompany($company)
            ->firstOrFail();

        return new UpdateTaskStatusAction(
            task: $task,
            newStatus: TaskStatusEnum::fromAlias((string) $input['status']),
            result: $this->normalizeResult($input['result'] ?? null),
            blockedReason: $input['blocked_reason'] ?? null,
        )->execute();
    }

    /**
     * The `result` input is a `Mixed` scalar, so a client may send an array OR a JSON string
     * (e.g. `"{\"log\":\"...\"}"`). The action stores an array, so coerce: decode a JSON-object
     * string, wrap any other non-empty string, pass arrays through, and treat empty/null as null.
     *
     * @return array<array-key, mixed>|null
     */
    private function normalizeResult(mixed $result): ?array
    {
        if ($result === null || $result === '') {
            return null;
        }

        if (is_array($result)) {
            return $result;
        }

        if (is_string($result)) {
            $decoded = json_decode($result, true);

            return is_array($decoded) ? $decoded : ['result' => $result];
        }

        return null;
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Plan $plan */
        $plan = Plan::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new DeletePlanAction($plan)->execute();
    }

    public function deleteTask(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Task $task */
        $task = Task::query()
            ->where('id', (int) $request['id'])
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->firstOrFail();

        return new DeleteTaskAction($task)->execute();
    }
}
