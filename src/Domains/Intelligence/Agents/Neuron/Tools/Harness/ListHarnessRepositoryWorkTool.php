<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Connectors\OpenCode\Concerns\ResolvesCodingRepositoryForTool;
use Kanvas\Connectors\OpenCode\DataTransferObject\ResolvedCodingRepository;
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
 * What is already open on a repository, before starting something new.
 *
 * `list_self_hosted_coding_jobs` shows what THIS agent did; this shows what the repository has in
 * flight from anyone — other agents, other people. Without it two jobs get dispatched at the same area
 * and collide, and the second only finds out at merge time.
 */
#[AgentTool(name: 'List Coding Repository Open Work', category: 'coding')]
class ListHarnessRepositoryWorkTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use ResolvesCodingRepositoryForTool;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'list_coding_repository_open_work',
            description: 'List the open pull requests on a repository you can work on — including ones '
                . 'opened by other people. Check it before starting work that might overlap, and say so '
                . 'if something related is already open rather than duplicating it.',
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
                name: 'repository',
                type: PropertyType::STRING,
                description: 'Repository slug, clone URL or owner/name.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $repository): array
    {
        $resolved = $this->resolveCodingRepository($this->agent, $repository);

        if (! $resolved instanceof ResolvedCodingRepository) {
            return $resolved;
        }

        $repo = $resolved->repository;
        $open = $resolved->github->openPullRequests();

        return $this->ok(
            ['repository' => $repo->slug, 'open_pull_requests' => $open],
            guidance: $open === []
                ? 'Nothing open — the repository is clear.'
                : 'If any of these covers what you were about to start, say so and ask before duplicating it.'
        );
    }
}
