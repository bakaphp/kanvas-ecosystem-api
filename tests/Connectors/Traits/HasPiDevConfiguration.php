<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\PiDev\Client;
use Kanvas\Connectors\PiDev\Enums\ConfigurationEnum;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Builds a pi.dev Client backed by canned HTTP responses, so tests exercise the real
 * request-building and response-mapping path rather than a stubbed-out service.
 */
trait HasPiDevConfiguration
{
    /**
     * Create the Plan + Task that represents a pi.dev coding job for an agent, with the pi.dev
     * linkage custom fields set — the shape DispatchCodingJobAction produces.
     */
    protected function makeCodingTaskForAgent(
        Agent $agent,
        TaskStatusEnum $status = TaskStatusEnum::PENDING,
        ?string $pidevJobId = 'job-1',
    ): Task {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $agent->app,
                company: $agent->company,
                title: 'Coding: test',
                planType: 'coding_job',
                agent: $agent,
                description: 'test task',
            ),
            tasks: [
                new TaskData(plan: null, title: 'test task', description: 'test task', status: $status),
            ],
        )->execute();

        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();
        $task->agent_id = $agent->getId();
        $task->saveQuietly();

        $task->set(TaskCustomFieldEnum::PIDEV_JOB_ID->value, $pidevJobId);
        $task->set(TaskCustomFieldEnum::PIDEV_REPO_SLUG->value, 'widgets');
        $task->set(TaskCustomFieldEnum::PIDEV_STATUS->value, 'queued');

        return $task;
    }

    protected function configurePiDev(AppInterface $app, CompanyInterface $company): void
    {
        $app->set(ConfigurationEnum::BASE_URL->value, 'https://pidev.test');
        $company->set(ConfigurationEnum::API_TOKEN->value, 'test-pidev-token');
    }

    /**
     * @param list<Response> $responses Returned in order as the client makes requests.
     */
    protected function piDevClientReturning(
        AppInterface $app,
        CompanyInterface $company,
        array $responses,
    ): Client {
        $this->configurePiDev($app, $company);

        return new Client(
            $app,
            $company,
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function piDevJsonResponse(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
