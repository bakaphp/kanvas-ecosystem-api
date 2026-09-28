<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PiDev\Actions\CancelCodingJobAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\Connectors\Traits\HasPiDevConfiguration;
use Tests\TestCase;

final class CancelCodingJobActionTest extends TestCase
{
    use HasPiDevConfiguration;

    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);
    }

    public function testCancelSignalsPiDev(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent();
        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::IN_PROGRESS);

        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(202, ['jobId' => 'job-1', 'status' => 'cancelling']),
        ]);

        $result = new CancelCodingJobAction($task, $client)->execute();

        $this->assertSame($task->getId(), $result->getId());
    }

    public function testCancelSwallowsAlreadyFinishedConflict(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agent = $this->makeAgent();
        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::IN_PROGRESS);

        // pi.dev returns 409 when the job already finished — expected, not a fault.
        $client = $this->piDevClientReturning($app, $company, [
            $this->piDevJsonResponse(409, ['error' => 'job already completed']),
        ]);

        $result = new CancelCodingJobAction($task, $client)->execute();

        $this->assertSame($task->getId(), $result->getId());
    }

    public function testCancelIsNoOpForTerminalTask(): void
    {
        $agent = $this->makeAgent();
        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::DONE);

        // No client passed — a terminal task must return before any HTTP call.
        $result = new CancelCodingJobAction($task)->execute();

        $this->assertSame(TaskStatusEnum::DONE->value, $result->status);
    }
}
