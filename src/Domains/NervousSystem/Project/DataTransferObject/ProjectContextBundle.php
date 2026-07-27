<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\DataTransferObject;

/**
 * The assembled "what's going on" snapshot an agent reads before it acts on a project: the objective,
 * who's involved, the open work, the recent narrative (channel messages) and the structured trail
 * (ledger events). Produced by ProjectContextService; consumed by the PM/agent runtime (PR4).
 */
final readonly class ProjectContextBundle
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $project,
        /** @var array<int, array<string, mixed>> */
        public array $members,
        /** @var array<int, array<string, mixed>> */
        public array $plans,
        /** @var array<int, array<string, mixed>> */
        public array $recentMessages,
        /** @var array<int, array<string, mixed>> */
        public array $recentEvents,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'members' => $this->members,
            'plans' => $this->plans,
            'recent_messages' => $this->recentMessages,
            'recent_events' => $this->recentEvents,
        ];
    }
}
