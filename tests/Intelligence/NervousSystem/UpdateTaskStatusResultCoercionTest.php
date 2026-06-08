<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Models\Task;
use Tests\TestCase;

/**
 * Regression (Sentry KANVAS-ECOSYSTEM-5RQ): the `result` input is a `Mixed` scalar, so a client
 * may send a JSON string. The resolver must coerce it to an array before the action (which types
 * `result` as `?array`), instead of throwing a TypeError.
 */
final class UpdateTaskStatusResultCoercionTest extends TestCase
{
    public function testUpdateTaskStatusAcceptsResultAsJsonString(): void
    {
        $task = $this->task();

        $this->graphQL('
            mutation($id: ID!, $input: UpdateNervousSystemTaskStatusInput!) {
                updateNervousSystemTaskStatus(id: $id, input: $input) { id status }
            }
        ', [
            'id' => (string) $task->id,
            'input' => [
                'status' => 'completed',
                'result' => '{"log": "Búsqueda iniciada"}',
            ],
        ])->assertSuccessful();

        $reloaded = Task::query()->where('id', $task->id)->firstOrFail();
        $this->assertSame('done', $reloaded->status);
        $this->assertSame(['log' => 'Búsqueda iniciada'], $reloaded->result);
    }

    public function testUpdateTaskStatusWrapsPlainStringResult(): void
    {
        $task = $this->task();

        $this->graphQL('
            mutation($id: ID!, $input: UpdateNervousSystemTaskStatusInput!) {
                updateNervousSystemTaskStatus(id: $id, input: $input) { id status }
            }
        ', [
            'id' => (string) $task->id,
            'input' => ['status' => 'completed', 'result' => 'just text'],
        ])->assertSuccessful();

        $reloaded = Task::query()->where('id', $task->id)->firstOrFail();
        $this->assertSame(['result' => 'just text'], $reloaded->result);
    }

    private function task(): Task
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(app: $app, company: $company, title: 'p', planType: 'test', user: $user),
        )->execute();

        return new AddTaskAction(
            $plan,
            new TaskData(plan: $plan, title: 'do the thing'),
        )->execute();
    }
}
