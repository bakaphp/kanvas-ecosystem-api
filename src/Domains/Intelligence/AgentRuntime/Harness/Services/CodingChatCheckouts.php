<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Services;

use Illuminate\Support\Facades\Cache;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
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
        $key = 'coding-chat-checkouts:' . $this->agent->getId();
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
