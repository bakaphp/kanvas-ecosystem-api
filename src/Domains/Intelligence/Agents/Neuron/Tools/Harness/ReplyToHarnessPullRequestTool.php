<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Concerns\ResolvesCodingRepositoryForTool;
use Kanvas\Connectors\OpenCode\DataTransferObject\ResolvedCodingRepository;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Answers a reviewer on the pull request itself.
 *
 * Without it the loop is silent on the side that matters: the agent reads a review, pushes a fix, and
 * the reviewer sees commits appear with no word about what was addressed or why. The reply belongs in
 * the thread they are reading, not in a chat window they cannot see.
 *
 * One comment per pull request per turn, and deliberately NOT `TrackByInputs`: keying the run budget
 * by arguments hands every distinct comment body a fresh budget, so a model narrating its progress
 * can notify a reviewer four times in one round and never touch the cap. The destination, not the
 * text, is what "the same call" means for a tool that writes to a shared thread.
 */
#[AgentTool(name: 'Reply To Coding Pull Request', category: 'coding')]
class ReplyToHarnessPullRequestTool extends Tool implements RequiresSystemAgent
{
    use GuardsRepeatCalls;
    use ReportsToolOutcome;
    use ResolvesCodingRepositoryForTool;

    protected string $name = 'reply_to_coding_pull_request';

    protected ?string $description = 'Post ONE comment on the pull request a coding job produced — to say what you '
        . 'changed in response to a review, to answer a question, or to flag something you could '
        . 'not do. Write it for the reviewer reading the PR, not as a summary for this chat. '
        . 'Every call notifies a human, so say everything you have to say in a single comment: '
        . 'do not post an acknowledgement, a status update, or a follow-up confirming the same '
        . 'round. If you have nothing to report beyond having read the review, post nothing.';

    public function __construct(
        private readonly Agent $agent,
    ) {
        $this->initRepeatGuard();
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
                description: 'The coding job whose pull request to comment on.',
                required: true,
            ),
            new ToolProperty(
                name: 'comment',
                type: PropertyType::STRING,
                description: 'What to say. Plain, specific, and about this change.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id, string $comment): array
    {
        $body = trim($comment);

        if ($body === '') {
            return $this->invalidArgs('The comment is empty.');
        }

        return $this->oncePerTurn(
            ['job_id' => $job_id],
            fn (): array => $this->post($job_id, $body),
            note: 'You have already commented on this pull request in this turn, and that comment was '
                . 'posted. Nothing further was posted now. Each comment notifies the reviewer, so one per '
                . 'round is the limit — anything else you wanted to say belonged in that comment. Do not '
                . 'call this again; report what you did in the chat instead.',
            rememberFailures: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function post(int $job_id, string $body): array
    {
        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);

        $url = $session === null ? null : Str::trimToNull((string) $session->pull_request_url);

        if ($session === null || $url === null || $session->repo_slug === null) {
            return $this->notFound(
                ['job_id' => $job_id],
                'Job ' . $job_id . ' has no pull request to comment on. Nothing was posted. Check the '
                    . 'job before assuming there is a PR.'
            );
        }

        $resolved = $this->resolveCodingRepository($this->agent, $session->repo_slug);

        if (! $resolved instanceof ResolvedCodingRepository) {
            return $resolved;
        }

        $service = $resolved->github;
        $number = (int) $session->pullRequestNumber();

        if ($number === 0 || ! $service->comment($number, $body . "\n\n_Posted by " . $this->agent->name . ' via Kanvas._')) {
            return $this->failed(
                'GitHub refused the comment. A fine-grained token needs Pull requests: write.',
                guidance: 'Nothing was posted — say so rather than implying the reviewer has been answered.'
            );
        }

        return $this->ok(['job_id' => $job_id, 'pull_request' => $url]);
    }
}
