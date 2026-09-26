<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\GitHubRepositoryService;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Reads what happened to a job's pull request after it left: the review, and whether CI passed.
 *
 * The missing half of the loop. Kanvas knows what the agent did and nothing about what anyone thought
 * of it, so the agent could only ever report "pushed" — a teammate that hands in work and never looks
 * at the comments.
 *
 * Reports the checks alongside the comments deliberately. Given only the comments, an agent will
 * cheerfully address the feedback on a branch whose tests are red and call it done.
 */
#[AgentTool(name: 'Read Coding Pull Request Feedback', category: 'coding')]
class ReadHarnessPullRequestFeedbackTool extends Tool implements HasRunKey, RequiresSystemAgent
{
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'read_coding_pull_request_feedback',
            description: 'Read the pull request a coding job produced: whether it is open, merged or '
                . 'closed, whether its checks passed, and every review comment on it. Use it when asked '
                . 'how a job landed, or before continuing one — then pass what the reviewer asked for to '
                . 'continue_self_hosted_coding_job.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'job_id',
                type: PropertyType::INTEGER,
                description: 'The coding job whose pull request to read.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id): array
    {
        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);

        if ($session === null) {
            return $this->notFound(
                ['job_id' => $job_id],
                'No coding job ' . $job_id . ' for this agent. Use list_self_hosted_coding_jobs to find '
                    . 'the right id.'
            );
        }

        $url = Str::trimToNull((string) $session->pull_request_url);

        if ($url === null) {
            return $this->noop(
                ['job_id' => $job_id],
                'Job ' . $job_id . ' has no pull request. Either it changed nothing, or the push '
                    . 'failed. Check the job itself before assuming there is feedback to read.'
            );
        }

        $number = (int) $session->pullRequestNumber();
        $repository = $session->repo_slug === null
            ? null
            : new RepoAllowListService($this->agent)->resolveOrFail($session->repo_slug);
        $token = Str::trimToNull((string) $this->agent->get(AgentCustomFieldEnum::GIT_TOKEN->value));

        if ($number === 0 || $repository === null || $token === null) {
            return $this->failed('Cannot reach the pull request for job ' . $job_id . '.');
        }

        try {
            $feedback = new GitHubRepositoryService($token, $repository->cloneUrl)->feedbackFor($number);
        } catch (Throwable $e) {
            report($e);

            return $this->failed($e->getMessage());
        }

        if ($feedback === null) {
            return $this->failed('GitHub did not return that pull request.');
        }

        return $this->ok(
            ['job_id' => $job_id, 'url' => $url, ...$feedback],
            guidance: $feedback['comments'] === []
                ? 'No comments yet — say so rather than inventing feedback.'
                : 'Report the comments as written and attribute them. To act on them, use '
                    . 'continue_self_hosted_coding_job on this job id so the same branch is updated.'
        );
    }
}
