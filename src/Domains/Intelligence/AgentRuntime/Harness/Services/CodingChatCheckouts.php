<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Services;

use Illuminate\Support\Facades\Cache;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Support\TaskDirectives;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * The checkouts a coding chat can open on its factory machine, so a message like "open Quorum-app"
 * switches the conversation instead of reaching the agent, which would otherwise go and work in a
 * folder it was never given. Cached briefly: the list changes when someone clones an app, not per turn.
 */
class CodingChatCheckouts
{
    private const int CACHE_SECONDS = 300;

    public function __construct(
        private readonly Agent $agent,
    ) {
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $key = $this->cacheKey();
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $repos = $this->fetch();

        // An unreachable machine answers with nothing; caching that would hide every checkout for minutes.
        if ($repos !== []) {
            Cache::put($key, $repos, self::CACHE_SECONDS);
        }

        return $repos;
    }

    /**
     * The one checkout the message names as a whole word, case-insensitively. Two named is not a
     * request to open either.
     */
    public function namedIn(string $message): ?string
    {
        $named = array_values(array_filter(
            $this->all(),
            static fn (string $repo): bool => preg_match(
                '/(?<![A-Za-z0-9._-])' . preg_quote($repo, '/') . '(?![A-Za-z0-9._-])/i',
                $message
            ) === 1
        ));

        return count($named) === 1 ? $named[0] : null;
    }

    /**
     * The checkout a workflow task works in. A `Repo: <name>` line decides: a kickoff names both the
     * template and the app it creates, and a guess between two would send the work to the wrong folder.
     * It is looked up past the cache, because the task after a kickoff names a checkout that did not
     * exist five minutes ago, and a declared checkout the machine does not have fails the task instead of
     * falling back to the agent's default. Without that line, the one checkout the text names.
     */
    public function forTask(string $text): ?string
    {
        $declared = TaskDirectives::repo($text);

        if ($declared === null) {
            return $this->namedIn($text);
        }

        $found = $this->find($declared, $this->all());

        if ($found !== null) {
            return $found;
        }

        // An empty listing is never cached, so each call reaches the machine again: list it once.
        Cache::forget($this->cacheKey());
        $repos = $this->all();

        return $this->find($declared, $repos) ?? throw new ValidationException(
            'The task says it works in "' . $declared . '", and the factory machine has no checkout by that name'
            . ($repos === [] ? ' (its checkouts could not be listed).' : ': it has ' . implode(', ', $repos) . '.')
            . ' A new app gets its checkout from a kickoff task in the template first.'
        );
    }

    /**
     * @param list<string> $repos
     */
    private function find(string $name, array $repos): ?string
    {
        foreach ($repos as $repo) {
            if (strcasecmp($repo, $name) === 0) {
                return $repo;
            }
        }

        return null;
    }

    private function cacheKey(): string
    {
        return 'coding-chat-checkouts:' . $this->agent->getId();
    }

    /**
     * @return list<string>
     */
    protected function fetch(): array
    {
        try {
            $machine = HarnessFactory::codingMachineFor($this->agent);

            return $machine === null
                ? []
                : HarnessFactory::codingFactoryOn($machine, $this->agent)->repos();
        } catch (Throwable) {
            return [];
        }
    }
}
