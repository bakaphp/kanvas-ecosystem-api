<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Spatie\LaravelData\Data;

class HarnessPrompt extends Data
{
    /**
     * @param array<string, string> $permissions tool-name pattern => allow|ask|deny, rendered into
     *                                           the runtime's own permission config at launch
     */
    public function __construct(
        public readonly string $task,
        public readonly string $policy,
        public readonly ?string $repoRules = null,
        public readonly ?string $persona = null,
        public readonly ?string $memories = null,
        public readonly ?string $handoff = null,
        public readonly array $permissions = [],
    ) {
    }

    /**
     * Immutable policy first, the concrete task last — a runtime that truncates context drops the
     * least important thing rather than the rules.
     */
    public function toText(): string
    {
        $blocks = array_filter([
            $this->policy,
            $this->repoRules,
            $this->memories,
            $this->handoff,
            $this->persona,
            $this->task,
        ], static fn (?string $block): bool => $block !== null && trim($block) !== '');

        return implode("\n\n---\n\n", $blocks);
    }
}
