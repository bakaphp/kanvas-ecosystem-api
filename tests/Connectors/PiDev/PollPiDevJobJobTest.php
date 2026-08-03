<?php

declare(strict_types=1);

namespace Tests\Connectors\PiDev;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PiDev\Jobs\PollPiDevJobJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\Connectors\Traits\HasPiDevConfiguration;
use Tests\TestCase;

final class PollPiDevJobJobTest extends TestCase
{
    use HasPiDevConfiguration;

    public function testTerminalTaskIsNotRescheduledAndMakesNoHttpCall(): void
    {
        Queue::fake();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        $task = $this->makeCodingTaskForAgent($agent, TaskStatusEnum::DONE);

        // A terminal task must return before building the HTTP client (no config set → would throw if it tried).
        new PollPiDevJobJob($app, $task->getId())->handle();

        Queue::assertNotPushed(PollPiDevJobJob::class);
    }
}
