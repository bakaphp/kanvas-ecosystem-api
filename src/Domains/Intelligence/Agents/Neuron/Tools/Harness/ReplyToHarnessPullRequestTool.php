<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Concerns\ResolvesCodingRepositoryForTool;
use Kanvas\Connectors\OpenCode\DataTransferObject\ResolvedCodingRepository;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Answers a reviewer on the pull request itself.
 *
 * Without it the loop is silent on the side that matters: the agent reads a review, pushes a fix, and
 * the reviewer sees commits appear with no word about what was addressed or why. The reply belongs in
 * the thread they are reading, not in a chat window they cannot see.
 */
#[AgentTool(name: 'Reply To Coding Pull Request', category: 'coding')]
class ReplyToHarnessPullRequestTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use ResolvesCodingRepositoryForTool;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'reply_to_coding_pull_request',
            description: 'Post a comment on the pull request a coding job produced — to say what you '
                . 'changed in response to a review, to answer a question, or to flag something you could '
                . 'not do. Write it for the reviewer reading the PR, not as a summary for this chat.',
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

        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);

        $url = $session === null ? null : Str::trimToNull((string) $session->pull_request_url);

        if ($session === null || $url === null || $session->repo_slug === null) {
            return $this->notFound(
                'Job ' . $job_id . ' has no pull request to comment on.',
                guidance: 'Nothing was posted. Check the job before assuming there is a PR.'
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
