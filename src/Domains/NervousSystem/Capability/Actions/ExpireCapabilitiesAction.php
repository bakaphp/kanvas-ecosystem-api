<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\AgentTool;

/**
 * Sweeps active grants whose `expires_at` has passed, marks them inactive,
 * and emits one `skill.expired` / `tool.expired` ledger event per grant.
 */
class ExpireCapabilitiesAction
{
    /**
     * @return array{skills_expired: int, tools_expired: int}
     */
    public function execute(): array
    {
        $skillsExpired = $this->expireSkills();
        $toolsExpired = $this->expireTools();

        return [
            'skills_expired' => $skillsExpired,
            'tools_expired' => $toolsExpired,
        ];
    }

    private function expireSkills(): int
    {
        $count = 0;

        AgentSkill::query()
            ->expired()
            ->where('is_deleted', 0)
            ->chunkById(100, function (Collection $grants) use (&$count): void {
                /** @var AgentSkill $grant */
                foreach ($grants as $grant) {
                    $grant->is_active = false;
                    $grant->saveOrFail();

                    $grant->emitLedgerEvent(
                        eventType: 'skill.expired',
                        payload: [
                            'agent_id' => $grant->agent_id,
                            'skill_id' => $grant->skill_id,
                            'expired_at' => $grant->expires_at?->toIso8601String(),
                        ],
                        actorType: 'System',
                        actorId: null,
                    );

                    $count++;
                }
            });

        return $count;
    }

    private function expireTools(): int
    {
        $count = 0;

        AgentTool::query()
            ->expired()
            ->where('is_deleted', 0)
            ->chunkById(100, function (Collection $grants) use (&$count): void {
                /** @var AgentTool $grant */
                foreach ($grants as $grant) {
                    $grant->is_active = false;
                    $grant->saveOrFail();

                    $grant->emitLedgerEvent(
                        eventType: 'tool.expired',
                        payload: [
                            'agent_id' => $grant->agent_id,
                            'tool_id' => $grant->tool_id,
                            'expired_at' => $grant->expires_at?->toIso8601String(),
                        ],
                        actorType: 'System',
                        actorId: null,
                    );

                    $count++;
                }
            });

        return $count;
    }
}
