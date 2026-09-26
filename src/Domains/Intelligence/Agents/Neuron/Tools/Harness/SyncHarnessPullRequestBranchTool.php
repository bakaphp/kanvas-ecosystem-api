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
 * Brings a pull request's branch up to date with the branch it targets.
 *
 * A review takes time, and the trunk moves while it happens. The pull request falls behind, eventually
 * conflicts, and becomes unmergeable — which lands on exactly the branches that got the most attention.
 * Nothing else here could refresh one, so the only way out was a human doing it by hand.
 */
#[AgentTool(name: 'Sync Coding Pull Request Branch', category: 'coding')]
class SyncHarnessPullRequestBranchTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use ResolvesCodingRepositoryForTool;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'sync_coding_pull_request_branch',
            description: 'Update a coding job\'s pull request with the latest changes from the branch it '
                . 'targets. Use it when read_coding_pull_request_feedback reports it is behind, or when a '
                . 'merge is blocked because the branch is out of date. A real conflict still needs a human.',
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
                description: 'The coding job whose pull request to bring up to date.',
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

        $url = $session === null ? null : Str::trimToNull((string) $session->pull_request_url);

        if ($session === null || $url === null || $session->repo_slug === null) {
            return $this->notFound(
                ['job_id' => $job_id],
                'Job ' . $job_id . ' has no pull request to update. Check the job before assuming there '
                    . 'is a branch to sync.'
            );
        }

        $resolved = $this->resolveCodingRepository($this->agent, $session->repo_slug);

        if (! $resolved instanceof ResolvedCodingRepository) {
            return $resolved;
        }

        $service = $resolved->github;
        $number = (int) $session->pullRequestNumber();
        $result = $number === 0
            ? ['updated' => false, 'error' => 'could not read the pull request number']
            : $service->syncBranch($number);

        if (($result['updated'] ?? false) !== true) {
            return $this->noop(
                ['job_id' => $job_id, 'error' => (string) ($result['error'] ?? 'unknown reason')],
                'The branch was not updated: ' . (string) ($result['error'] ?? 'unknown reason')
                    . '. Say exactly that. A conflict cannot be resolved from here and needs a person.'
            );
        }

        return $this->ok(
            ['job_id' => $job_id, 'pull_request' => $url],
            guidance: 'The branch now includes the latest base. Checks will re-run; do not report the '
                . 'result until they have.'
        );
    }
}
