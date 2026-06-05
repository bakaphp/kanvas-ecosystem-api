<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;

class RevokeSkillFromAgentAction
{
    public function __construct(
        protected readonly AgentSkill $grant,
        protected readonly ?int $actorUserId = null,
        protected readonly ?string $reason = null,
    ) {
    }

    public function execute(): AgentSkill
    {
        return DB::connection('intelligence')->transaction(function (): AgentSkill {
            $this->grant->is_active = false;
            $this->grant->is_deleted = true;
            $this->grant->saveOrFail();

            $this->grant->emitLedgerEvent(
                eventType: 'skill.revoked',
                payload: [
                    'agent_id' => $this->grant->agent_id,
                    'skill_id' => $this->grant->skill_id,
                    'reason' => $this->reason,
                ],
                actorType: $this->actorUserId !== null ? 'User' : 'System',
                actorId: $this->actorUserId,
            );

            return $this->grant;
        });
    }
}
