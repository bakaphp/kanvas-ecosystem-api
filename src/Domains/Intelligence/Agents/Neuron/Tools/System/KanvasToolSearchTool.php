<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\System;

use NeuronAI\Agent\Middleware\ToolSearchTool;
use NeuronAI\Tools\ToolInterface;
use Override;

/**
 * The stock search answers a miss with "No tools found matching 'x'", which the model reads as "try
 * another word": one turn ran nine searches for a WhatsApp tool that does not exist. A miss here
 * lists every tool in the pool and says the list is complete, and past the per-turn cap the search
 * stops running and answers the same way.
 */
class KanvasToolSearchTool extends ToolSearchTool
{
    public const int MAX_SEARCHES_PER_TURN = 4;

    /**
     * @param ToolInterface[] $toolPool
     * @param int<1,max> $topN
     * @param int $searchesThisTurn how many times the model already searched since its last message
     * @param ToolInterface[] $declaredTools the tools the request already carries, callable without a search
     */
    public function __construct(
        array $toolPool,
        int $topN = 5,
        private readonly int $searchesThisTurn = 0,
        private readonly array $declaredTools = [],
    ) {
        parent::__construct($toolPool, $topN);
    }

    #[Override]
    public function __invoke(string $query): string
    {
        if ($this->searchesThisTurn >= self::MAX_SEARCHES_PER_TURN) {
            return $this->closed(sprintf('You have already searched %d times this turn; the search is closed.', $this->searchesThisTurn));
        }

        $shown = $this->search($query);
        if ($shown === []) {
            $declared = $this->declaredMatches($query);

            return $declared === []
                ? $this->closed("No tool matches '{$query}'.")
                : sprintf(
                    "No pooled tool matches '%s', and none is needed: you already hold %s. Call them directly; tool_search only finds tools that are not declared in your request.",
                    $query,
                    implode(', ', $declared),
                );
        }

        return parent::__invoke($query) . $this->unshownMatches($query, $shown);
    }

    /**
     * The pooled tools as the model should hear about them: plain names sorted, and an MCP toolkit as
     * one line with its count, because 29 `kernel__*` names read as a menu and the model tried them one
     * by one as a workaround for the capability it was missing.
     *
     * @param ToolInterface[] $toolPool
     * @return list<string>
     */
    public static function inventory(array $toolPool): array
    {
        $names = [];
        $toolkits = [];
        foreach ($toolPool as $tool) {
            $name = $tool->getName();
            $server = strstr($name, '__', true);
            if ($server !== false && $server !== '') {
                $toolkits[$server] = ($toolkits[$server] ?? 0) + 1;
            } else {
                $names[] = $name;
            }
        }
        sort($names);
        ksort($toolkits);

        foreach ($toolkits as $server => $count) {
            $names[] = sprintf('the %s MCP toolkit (%d tools, prefixed %s__)', $server, $count, $server);
        }

        return $names;
    }

    /**
     * A hit loads the top N of every tool that matched; unless the rest are named, the model takes the
     * five it sees for the whole toolkit (nine kias__ tools reported as five, then nine, then five).
     *
     * @param ToolInterface[] $shown
     */
    private function unshownMatches(string $query, array $shown): string
    {
        $shownNames = array_map(static fn (ToolInterface $tool): string => $tool->getName(), $shown);
        $unshown = [];
        foreach (new ToolSearchTool($this->toolPool, max(1, count($this->toolPool)))->search($query) as $tool) {
            if (! in_array($tool->getName(), $shownNames, true)) {
                $unshown[] = $tool->getName();
            }
        }

        if ($unshown === []) {
            return '';
        }

        return sprintf(
            "\nShowing %d of %d matches. Also matching, not loaded: %s. Search for one by its exact name to load it with its parameters.",
            count($shown),
            count($shown) + count($unshown),
            implode(', ', $unshown),
        );
    }

    /**
     * Declared tools whose name shares a word with the query: a search for "plan" on an agent holding
     * create_plan is a search for a tool it can already call. Exact words only; the stock fuzzy match
     * paired "chat" with get_current_time, and a wrong "you already hold" is worse than the miss list.
     *
     * @return list<string>
     */
    private function declaredMatches(string $query): array
    {
        $keywords = $this->words($query);
        if ($keywords === []) {
            return [];
        }

        $names = [];
        foreach ($this->declaredTools as $tool) {
            if (array_intersect($keywords, $this->words($tool->getName())) !== []) {
                $names[] = $tool->getName();
            }
        }

        return array_slice($names, 0, $this->topN);
    }

    /**
     * @return list<string> lowercase words of three letters or more, plural `s` dropped
     */
    private function words(string $text): array
    {
        preg_match_all('/[a-z0-9]{3,}/', strtolower($text), $matches);

        return array_values(array_unique(array_map(static fn (string $word): string => rtrim($word, 's'), $matches[0])));
    }

    private function closed(string $reason): string
    {
        $reply = <<<'REPLY'
            %s

            This list is complete: the %d searchable tools you hold are %s.
            A tool not in this list is not available to you in this conversation, and searching again will not find it.
            Do not probe other tools looking for a workaround.
            Answer the user now: say plainly what you cannot do, or record it with report_capability_gap if you hold that tool.
            REPLY;

        return sprintf(
            $reply,
            $reason,
            count($this->toolPool),
            implode(', ', self::inventory($this->toolPool)),
        );
    }
}
