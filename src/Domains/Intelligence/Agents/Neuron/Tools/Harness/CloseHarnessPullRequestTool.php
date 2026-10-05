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
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Closes a pull request this agent opened and has since moved on from.
 *
 * The narrow case it exists for: work is redone elsewhere, or a job is superseded, and the original
 * pull request sits open pointing at a branch nobody will merge. The agent created that clutter, so it
 * can clear it — but only where nobody has reviewed it yet. A reviewed pull request belongs to its
 * reviewer, and merging stays a human's decision either way.
 *
 * Deliberately NOT `TrackByInputs`: closing is per pull request, and the destination is what "the same
 * call" means, exactly as on {@see ReplyToHarnessPullRequestTool}.
 */
#[AgentTool(name: 'Close Coding Pull Request', category: 'coding')]
class CloseHarnessPullRequestTool extends Tool implements RequiresSystemAgent
{
    use ReportsToolOutcome;
    use ResolvesCodingRepositoryForTool;

    protected string $name = 'close_coding_pull_request';

    protected ?string $description = 'Close a pull request one of your jobs opened, when the work has moved somewhere '
        . 'else or been redone and the pull request is now stale. The reason is posted on the '
        . 'thread first and must say where the work went, because a pull request that shuts with '
        . 'no explanation reads as abandoned. Refused on anything a person has already reviewed — '
        . 'that is their call. You can NEVER merge; that is always a human decision.';

    public function __construct(
        private readonly Agent $agent,
    ) {
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
                description: 'The job whose pull request to close.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why it is being closed and where the work went — a link or a pull request '
                    . 'number, not just "superseded".',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id, string $reason): array
    {
        $body = trim($reason);

        if ($body === '') {
            return $this->invalidArgs(
                'A reason is required.',
                guidance: 'Say what replaced this work before closing it.'
            );
        }

        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);
        $url = $session === null ? null : Str::trimToNull((string) $session->pull_request_url);

        if ($session === null || $url === null || $session->repo_slug === null) {
            return $this->notFound(
                ['job_id' => $job_id],
                'Job ' . $job_id . ' has no pull request to close. Nothing was changed.'
            );
        }

        $resolved = $this->resolveCodingRepository($this->agent, $session->repo_slug);

        if (! $resolved instanceof ResolvedCodingRepository) {
            return $resolved;
        }

        $number = (int) $session->pullRequestNumber();

        if ($number === 0) {
            return $this->notFound(
                ['job_id' => $job_id],
                'Job ' . $job_id . '\'s pull request url could not be read as a number.'
            );
        }

        $result = $resolved->github->close(
            $number,
            $body . "\n\n_Closed by " . $this->agent->name . ' via Kanvas._'
        );

        if ($result['closed'] === false) {
            return $this->denied(
                $result['reason'],
                ['job_id' => $job_id, 'pull_request' => $url],
                guidance: 'The pull request is still open. Say so plainly and give the reason as written.'
            );
        }

        // The outcome is carried rather than asserted: a pull request somebody else already closed also
        // arrives here, and no reason was posted on that one. Saying "closed, with the reason on the
        // thread" would have the agent report a comment it never made.
        return $this->ok(
            ['job_id' => $job_id, 'pull_request' => $url, 'outcome' => $result['reason']],
            guidance: 'Report `outcome` as written, and say which pull request and where the work went.'
        );
    }
}
