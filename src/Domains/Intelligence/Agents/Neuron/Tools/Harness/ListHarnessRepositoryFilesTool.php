<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Baka\Support\Str;
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
 * Lists what is in a repository, so a brief can name real paths.
 *
 * The companion to reading a file: you cannot read the right file until you know it exists. A path an
 * LLM invents produces a task about a file that is not there, and the coding agent has no way to ask.
 */
#[AgentTool(name: 'List Coding Repository Files', category: 'coding')]
class ListHarnessRepositoryFilesTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use ResolvesCodingRepositoryForTool;
    use TrackByInputs;

    private const int MAX_PATHS = 300;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'list_coding_repository_files',
            description: 'List the files in a repository you can work on, without starting a job. Filter '
                . 'with a path prefix or a fragment to find where something lives. Use it before writing '
                . 'a task so the brief names paths that actually exist.',
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
            new ToolProperty(
                name: 'matching',
                type: PropertyType::STRING,
                description: 'Only paths containing this, e.g. "src/Billing" or "Controller". Omit for all.',
                required: false,
            ),
            new ToolProperty(
                name: 'ref',
                type: PropertyType::STRING,
                description: 'Branch or commit to list. Defaults to the repository\'s base branch.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $repository, ?string $matching = null, ?string $ref = null): array
    {
        $resolved = $this->resolveCodingRepository($this->agent, $repository);

        if (! $resolved instanceof ResolvedCodingRepository) {
            return $resolved;
        }

        $repo = $resolved->repository;
        $paths = $resolved->github->listFiles(Str::trimToNull((string) $ref) ?? $repo->baseBranch);

        if ($paths === null) {
            return $this->failed('Could not list ' . $repo->slug . '.');
        }

        $needle = Str::trimToNull((string) $matching);

        if ($needle !== null) {
            $paths = array_values(array_filter(
                $paths,
                static fn (string $path): bool => mb_stripos($path, $needle) !== false
            ));
        }

        $total = count($paths);

        return $this->ok(
            [
                'repository' => $repo->slug,
                'total' => $total,
                'truncated' => $total > self::MAX_PATHS,
                'paths' => array_slice($paths, 0, self::MAX_PATHS),
            ],
            guidance: $total === 0
                ? 'Nothing matched. Try a shorter fragment rather than guessing a path.'
                : null
        );
    }
}
