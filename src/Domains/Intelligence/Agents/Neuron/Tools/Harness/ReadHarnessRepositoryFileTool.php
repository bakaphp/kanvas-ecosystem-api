<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Concerns\ResolvesCodingRepositoryForTool;
use Kanvas\Connectors\OpenCode\DataTransferObject\ResolvedCodingRepository;
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

/**
 * Reads one file out of a repository, before any job exists.
 *
 * A brief written without looking is a guess: the wrong file, a helper that already exists, a
 * convention the repository settled years ago. The coding agent cannot ask a follow-up question once it
 * starts, so the orienting has to happen here, on this side, while there is still someone to ask.
 *
 * Reads through the API rather than a container, because the useful moment is before a workspace exists.
 * Truncated on purpose — this is for orienting, not for pulling a codebase into the context window.
 */
#[AgentTool(name: 'Read Coding Repository File', category: 'coding')]
class ReadHarnessRepositoryFileTool extends Tool implements HasRunKey, RequiresSystemAgent
{
    use ReportsToolOutcome;
    use ResolvesCodingRepositoryForTool;
    use TrackByInputs;

    private const int MAX_CHARS = 20000;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'read_coding_repository_file',
            description: 'Read a file from a repository you can work on, without starting a job. Use it '
                . 'to check what the code actually does before writing a task — the conventions in use, '
                . 'whether a helper already exists, what a file is called. Long files are truncated.',
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
                name: 'path',
                type: PropertyType::STRING,
                description: 'Path within the repository, e.g. src/Service/Billing.php',
                required: true,
            ),
            new ToolProperty(
                name: 'ref',
                type: PropertyType::STRING,
                description: 'Branch or commit to read from. Defaults to the repository\'s base branch.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $repository, string $path, ?string $ref = null): array
    {
        $resolved = $this->resolveCodingRepository($this->agent, $repository);

        if (! $resolved instanceof ResolvedCodingRepository) {
            return $resolved;
        }

        $repo = $resolved->repository;
        $contents = $resolved->github->readFile(trim($path), Str::trimToNull((string) $ref) ?? $repo->baseBranch);

        if ($contents === null) {
            return $this->notFound(
                ['path' => $path, 'repository' => $repo->slug],
                'No file "' . $path . '" in ' . $repo->slug . '. Check the path with '
                    . 'list_coding_repository_files rather than guessing again.'
            );
        }

        return $this->ok([
            'repository' => $repo->slug,
            'path' => $path,
            'truncated' => mb_strlen($contents) > self::MAX_CHARS,
            'contents' => mb_substr($contents, 0, self::MAX_CHARS),
        ]);
    }
}
