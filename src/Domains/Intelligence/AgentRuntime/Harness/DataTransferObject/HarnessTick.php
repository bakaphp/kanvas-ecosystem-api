<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Spatie\LaravelData\Data;

/**
 * One poll of a session. The poller sees only this — never a runtime-specific payload.
 */
class HarnessTick extends Data
{
    /**
     * @param list<string>                     $narration      new agent text since the last tick
     * @param list<HarnessPermissionRequest>   $permissions    requests parked, waiting on a decision
     * @param list<string>                     $questions      questions the agent asked, verbatim
     * @param list<string>                     $modelsObserved every model that produced a message this
     *                                                         tick; a value other than the pinned one
     *                                                         means the runtime substituted it
     * @param string|null                      $cursor         where to resume reading the conversation.
     *                                                         Opaque and owned by the runtime — store
     *                                                         it, never parse or compare it
     */
    public function __construct(
        public readonly HarnessStatusEnum $status,
        public readonly HarnessUsage $usage,
        public readonly array $narration = [],
        public readonly array $permissions = [],
        public readonly array $questions = [],
        public readonly array $modelsObserved = [],
        public readonly ?string $cursor = null,
        public readonly ?int $lastMessageAt = null,
        public readonly ?string $error = null,
    ) {
    }

    public function hasNarration(): bool
    {
        return $this->narration !== [];
    }

    /**
     * @param list<string> $expected
     */
    public function substitutedModel(array $expected): ?string
    {
        foreach ($this->modelsObserved as $model) {
            if (! in_array($model, $expected, true)) {
                return $model;
            }
        }

        return null;
    }
}
