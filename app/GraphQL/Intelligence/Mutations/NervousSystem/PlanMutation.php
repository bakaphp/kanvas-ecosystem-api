<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\NervousSystem;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\Actions\ApprovePlanAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
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

        $tasks = [];
        foreach ($input['tasks'] ?? [] as $sequence => $taskInput) {
            $tasks[] = new TaskData(
                plan: null, // wired by CreatePlanAction once the parent plan exists
                title: (string) $taskInput['title'],
                sequence: isset($taskInput['sequence']) ? (int) $taskInput['sequence'] : $sequence,
                description: $taskInput['description'] ?? null,
                status: isset($taskInput['status'])
                    ? TaskStatusEnum::from((string) $taskInput['status'])
                    : TaskStatusEnum::PENDING,
                result: $taskInput['result'] ?? null,
                blockedReason: $taskInput['blocked_reason'] ?? null,
            );
        }

        /** @var Agent|null $planAgent */
        $planAgent = isset($input['agent_id'])
            ? Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app)
            : null;

        /** @var Users|null $planUser */
        $planUser = isset($input['users_id'])
            ? Users::getById((int) $input['users_id'])
            : $user;

        /** @var Plan|null $parentPlan */
        $parentPlan = isset($input['parent_plan_id'])
            ? Plan::query()
                ->where('id', (int) $input['parent_plan_id'])
                ->fromApp($app)
                ->fromCompany($company)
                ->firstOrFail()
            : null;

        return new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: (string) $input['title'],
                planType: (string) $input['plan_type'],
                agent: $planAgent,
                user: $planUser,
                parentPlan: $parentPlan,
                entityNamespace: $input['entity_namespace'] ?? null,
                entityId: isset($input['entity_id']) ? (int) $input['entity_id'] : null,
                description: $input['description'] ?? null,
                status: isset($input['status'])
                    ? PlanStatusEnum::from((string) $input['status'])
                    : PlanStatusEnum::DRAFT,
                priority: isset($input['priority']) ? (int) $input['priority'] : 0,
                deadlineAt: isset($input['deadline_at']) ? Carbon::parse((string) $input['deadline_at']) : null,
                input: $input['input'] ?? null,
                output: $input['output'] ?? null,
                confidenceScore: isset($input['confidence_score']) ? (float) $input['confidence_score'] : null,
                requiresHumanApproval: (bool) ($input['requires_human_approval'] ?? false),
            ),
            tasks: $tasks,
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Plan
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Plan $plan */
        $plan = Plan::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdatePlanAction(
            $plan,
            new PlanData(
                app: $app,
                company: $company,
                title: (string) ($input['title'] ?? $plan->title),
                planType: $plan->plan_type,
                agent: null,
                user: null,
                parentPlan: null,
                entityNamespace: $plan->entity_namespace,
                entityId: $plan->entity_id,
                description: $input['description'] ?? $plan->description,
                status: isset($input['status'])
                    ? PlanStatusEnum::from((string) $input['status'])
                    : PlanStatusEnum::from($plan->status),
                priority: isset($input['priority']) ? (int) $input['priority'] : $plan->priority,
                deadlineAt: array_key_exists('deadline_at', $input)
                    ? ($input['deadline_at'] !== null ? Carbon::parse((string) $input['deadline_at']) : null)
                    : $plan->deadline_at,
                input: array_key_exists('input', $input) ? $input['input'] : $plan->input,
                output: array_key_exists('output', $input) ? $input['output'] : $plan->output,
                confidenceScore: array_key_exists('confidence_score', $input)
                    ? ($input['confidence_score'] !== null ? (float) $input['confidence_score'] : null)
                    : ($plan->confidence_score !== null ? (float) $plan->confidence_score : null),
                requiresHumanApproval: $plan->requires_human_approval,
            ),
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
        $input = $request['input'];

        /** @var Plan $plan */
        $plan = Plan::getByIdFromCompanyApp((int) $request['plan_id'], $company, $app);

        return new AddTaskAction(
            $plan,
            new TaskData(
                plan: $plan,
                title: (string) $input['title'],
                sequence: isset($input['sequence']) ? (int) $input['sequence'] : 0,
                description: $input['description'] ?? null,
                status: isset($input['status'])
                    ? TaskStatusEnum::from((string) $input['status'])
                    : TaskStatusEnum::PENDING,
                result: $input['result'] ?? null,
                blockedReason: $input['blocked_reason'] ?? null,
            ),
        )->execute();
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
            newStatus: TaskStatusEnum::from((string) $input['status']),
            result: $input['result'] ?? null,
            blockedReason: $input['blocked_reason'] ?? null,
        )->execute();
    }
}
