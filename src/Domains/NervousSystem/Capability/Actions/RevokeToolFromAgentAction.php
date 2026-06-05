<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Capability\Models\AgentTool;

class RevokeToolFromAgentAction
{
    public function __construct(
        protected readonly AgentTool $grant,
        protected readonly ?int $actorUserId = null,
        protected readonly ?string $reason = null,
    ) {
    }

    public function execute(): AgentTool
    {
        return DB::connection('intelligence')->transaction(function (): AgentTool {
            // Sibling cleanup: the (agent_id, tool_id, is_deleted) unique key would otherwise reject this flip if a soft-deleted ghost exists.
            AgentTool::withTrashed()
                ->where('agent_id', $this->grant->agent_id)
                ->where('tool_id', $this->grant->tool_id)
                ->where('id', '!=', $this->grant->getKey())
                ->forceDelete();

            $this->grant->is_active = false;
            $this->grant->is_deleted = true;
            $this->grant->saveOrFail();

            $this->grant->emitLedgerEvent(
                eventType: 'tool.revoked',
                payload: [
                    'agent_id' => $this->grant->agent_id,
                    'tool_id' => $this->grant->tool_id,
                    'reason' => $this->reason,
                ],
                actorType: $this->actorUserId !== null ? 'User' : 'System',
                actorId: $this->actorUserId,
            );

            return $this->grant;
        });
    }
}
