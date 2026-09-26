<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Actions;

use Kanvas\Connectors\PiDev\Client;
use Kanvas\Connectors\PiDev\DataTransferObject\PiDevWorkRequest;
use Kanvas\Connectors\PiDev\Enums\CustomFieldEnum;
use Kanvas\Connectors\PiDev\Enums\JobStatusEnum;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\PiDev\Jobs\PollPiDevJobJob;
use Kanvas\Connectors\PiDev\Services\PromptBuilder;
use Kanvas\Connectors\PiDev\Services\RepoAllowListService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreateAgentRunPlanAction;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;

class DispatchCodingJobAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly string $repoSlug,
        private readonly string $task,
        private readonly ?Client $client = null,
        private readonly ?Users $requestedBy = null,
    ) {
    }

    public function execute(): Task
    {
        if (trim($this->task) === '') {
            throw new ValidationException('A task description is required');
        }

        $token = $this->agent->get(CustomFieldEnum::PIDEV_GITHUB_TOKEN->value);
        if (! is_string($token) || $token === '') {
            throw new ValidationException('This agent is not configured for pi.dev (missing GitHub token)');
        }

        $repo = RepoAllowListService::resolve($this->agent, $this->repoSlug);
        $persona = $this->agent->get(CustomFieldEnum::PIDEV_SYSTEM_PROMPT->value);

        $request = new PiDevWorkRequest(
            agentId: $this->agent->uuid,
            githubToken: $token,
            workingGithubRepoUrl: (string) $repo['url'],
            task: $this->task,
            systemPrompt: PromptBuilder::build($repo, is_string($persona) ? $persona : null),
        );

        $client = $this->client ?? new Client($this->agent->app, $this->agent->company);
        $response = $client->queueWork($request->toApiPayload());

        $task = $this->recordAsTask((string) $repo['url'], $response);

        PollPiDevJobJob::dispatch($this->agent->app, $task->getId());

        return $task;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function recordAsTask(string $repoUrl, array $response): Task
    {
        $task = new CreateAgentRunPlanAction(
            agent: $this->agent,
            brief: $this->task,
            planType: 'coding_job',
            requestedBy: $this->requestedBy,
            input: ['repo_slug' => $this->repoSlug, 'repo_url' => $repoUrl],
        )->execute();

        $task->set(
            TaskCustomFieldEnum::PIDEV_JOB_ID->value,
            isset($response['jobId']) ? (string) $response['jobId'] : null
        );
        $task->set(TaskCustomFieldEnum::PIDEV_AGENT_ID->value, $this->agent->uuid);
        $task->set(TaskCustomFieldEnum::PIDEV_REPO_SLUG->value, $this->repoSlug);
        $task->set(TaskCustomFieldEnum::PIDEV_REPO_URL->value, $repoUrl);
        $task->set(TaskCustomFieldEnum::PIDEV_STATUS->value, JobStatusEnum::fromApiResponse($response)->value);

        return $task;
    }
}
