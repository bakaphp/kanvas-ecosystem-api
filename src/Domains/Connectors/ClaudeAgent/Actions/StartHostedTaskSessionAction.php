<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Illuminate\Support\Str;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Jobs\PollClaudeSessionJob;
use Kanvas\Connectors\ClaudeAgent\Services\RepoAllowListService;
use Kanvas\Connectors\ClaudeAgent\Traits\ResolvesClaudeClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Attach a hosted session to a Task that already exists and hand off to the poller.
 *
 * Split out from {@see DispatchLongTaskAction} because there are two entry points with the same
 * ending: a tool call creates the Plan + Task first, whereas a PM assignment already has one — and
 * creating a second Task for an assignment would duplicate the board.
 *
 * **Returns as soon as the session is open.** Nothing here may block: a queue worker caps out around
 * an hour and a hosted session can legitimately run longer.
 */
class StartHostedTaskSessionAction
{
    use ResolvesClaudeClient;

    /**
     * @param list<string> $repoSlugs Restrict mounted repos; empty means the agent's whole allow-list.
     */
    public function __construct(
        protected readonly Task $task,
        protected readonly Agent $agent,
        protected readonly string $brief,
        protected readonly ?string $rubric = null,
        protected readonly array $repoSlugs = [],
        protected readonly ?int $maxIterations = null,
        protected readonly ?Client $client = null,
    ) {
    }

    /**
     * Everything that can be rejected without side effects. Called here AND by
     * {@see DispatchLongTaskAction} before it creates the plan — an unknown slug must fail before
     * there is a plan to orphan.
     *
     * @param list<string> $repoSlugs
     */
    public static function assertDispatchable(Agent $agent, string $brief, array $repoSlugs): string
    {
        $brief = trim($brief);

        if ($brief === '') {
            throw new ValidationException('A task brief is required');
        }

        foreach ($repoSlugs as $slug) {
            RepoAllowListService::resolve($agent, $slug);
        }

        return $brief;
    }

    /**
     * @return string The hosted session id.
     */
    public function execute(): string
    {
        $brief = self::assertDispatchable($this->agent, $this->brief, $this->repoSlugs);

        $app = $this->agent->app;
        $company = $this->agent->company;
        $client = $this->claudeClient($app, $company);

        $environmentId = new EnsureEnvironmentAction($app, $company, $client)->execute();
        $remoteAgent = new PushAgentDefinitionAction($this->agent, $client)->execute();

        $sessionId = new OpenSessionAction(
            agent: $this->agent,
            session: null,
            environmentId: $environmentId,
            remoteAgentId: $remoteAgent['id'],
            remoteAgentVersion: $remoteAgent['version'],
            client: $client,
            repoSlugs: $this->repoSlugs,
            initialEvents: $this->initialEvents($brief),
            title: Str::limit($brief, 80),
        )->execute();

        $this->task->set(TaskCustomFieldEnum::CLAUDE_SESSION_ID->value, $sessionId);
        $this->task->set(TaskCustomFieldEnum::CLAUDE_STATUS->value, 'running');
        $this->task->set(TaskCustomFieldEnum::CLAUDE_STARTED_AT->value, now()->toIso8601String());

        if ($this->repoSlugs !== []) {
            $this->task->set(TaskCustomFieldEnum::CLAUDE_REPO_SLUG->value, implode(',', $this->repoSlugs));
        }

        PollClaudeSessionJob::dispatch($app, $this->task->getId());

        return $sessionId;
    }

    /**
     * Seeding the session starts the agent loop in the same call, so it is running before we return.
     * An outcome and a plain message are mutually exclusive — sending both has the agent work the
     * brief twice.
     *
     * @return list<array<string, mixed>>
     */
    protected function initialEvents(string $brief): array
    {
        if ($this->rubric !== null && trim($this->rubric) !== '') {
            return [[
                'type' => 'user.define_outcome',
                'description' => $brief,
                'rubric' => ['type' => 'text', 'content' => trim($this->rubric)],
                'max_iterations' => $this->maxIterations ?? 3,
            ]];
        }

        return [[
            'type' => 'user.message',
            'content' => [['type' => 'text', 'text' => $brief]],
        ]];
    }
}
