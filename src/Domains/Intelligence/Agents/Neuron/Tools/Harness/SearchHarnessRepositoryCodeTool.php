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
 * Searches what the code SAYS, not what the files are called.
 *
 * The other half of orienting. Listing paths answers "is there a file named this"; the question people
 * actually have is "where is this handled", and a filename cannot answer it. Without this the agent
 * guesses a path, reads the wrong file, and writes a brief against code that is not there.
 */
#[AgentTool(name: 'Search Coding Repository Code', category: 'coding')]
class SearchHarnessRepositoryCodeTool extends Tool implements HasRunKey, RequiresSystemAgent
{
    use ReportsToolOutcome;
    use ResolvesCodingRepositoryForTool;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'search_coding_repository_code',
            description: 'Search the CONTENTS of a repository you can work on — a function name, a class, '
                . 'a string, a config key. Use it to find where something lives before writing a task. '
                . 'To search by filename instead, use list_coding_repository_files.',
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
                name: 'query',
                type: PropertyType::STRING,
                description: 'What to look for, e.g. "RateLimiter" or "stripe_webhook_secret". A symbol '
                    . 'or a distinctive phrase works far better than a sentence.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $repository, string $query): array
    {
        $needle = Str::trimToNull($query);

        if ($needle === null) {
            return $this->invalidArgs('The search query is empty.');
        }

        $resolved = $this->resolveCodingRepository($this->agent, $repository);

        if (! $resolved instanceof ResolvedCodingRepository) {
            return $resolved;
        }

        $repo = $resolved->repository;
        $hits = $resolved->github->searchCode($needle);

        if ($hits === null) {
            return $this->failed('Could not search ' . $repo->slug . '.');
        }

        return $this->ok(
            ['repository' => $repo->slug, 'query' => $needle, 'matches' => $hits],
            guidance: $hits === []
                // GitHub's index lags a push and ignores some file types, so "nothing" is genuinely
                // ambiguous here — saying it does not exist would be a stronger claim than the evidence.
                ? 'No matches. That may mean it is not there, or that the index has not caught up — do '
                    . 'not state it does not exist. Try a different symbol, or list the files instead.'
                : null
        );
    }
}
