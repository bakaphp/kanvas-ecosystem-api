<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Tool;

class GrantToolToAgentAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly Tool $tool,
        protected readonly ?int $grantedByUserId = null,
        protected readonly ?Carbon $expiresAt = null,
        protected readonly ?array $config = null,
    ) {
    }

    public function execute(): AgentTool
    {
        $this->validateProviderCompatibility();

        return DB::connection('intelligence')->transaction(function (): AgentTool {
            // Don't filter by is_deleted here — a previously revoked row
            // should be reactivated, not duplicated, when granting again.
            $existing = AgentTool::query()
                ->where('agent_id', $this->agent->getId())
                ->where('tool_id', $this->tool->getId())
                ->first();

            if ($existing instanceof AgentTool) {
                $existing->is_active = true;
                $existing->is_deleted = false;
                $existing->granted_by_users_id = $this->grantedByUserId;
                $existing->granted_at = Carbon::now();
                $existing->expires_at = $this->expiresAt;
                $existing->config = $this->config;
                $existing->saveOrFail();
                $grant = $existing;
            } else {
                $grant = new AgentTool();
                $grant->apps_id = $this->agent->apps_id;
                $grant->companies_id = $this->agent->companies_id;
                $grant->agent_id = $this->agent->getId();
                $grant->tool_id = $this->tool->getId();
                $grant->granted_by_users_id = $this->grantedByUserId;
                $grant->granted_at = Carbon::now();
                $grant->expires_at = $this->expiresAt;
                $grant->is_active = true;
                $grant->is_deleted = false;
                $grant->config = $this->config;
                $grant->saveOrFail();
            }

            $grant->emitLedgerEvent('tool.granted', payload: [
                'agent_id' => $grant->agent_id,
                'tool_id' => $grant->tool_id,
                'tool_name' => $this->tool->name,
                'expires_at' => $this->expiresAt?->toIso8601String(),
            ]);

            return $grant;
        });
    }

    private function validateProviderCompatibility(): void
    {
        $provider = $this->agent->type?->provider ?? null;

        if ($provider === null) {
            return;
        }

        /** @var array<int, string> $toolFrameworks */
        $toolFrameworks = $this->tool->frameworks;

        if (! in_array($provider, $toolFrameworks, true)) {
            throw new ValidationException(sprintf(
                'Tool "%s" does not support framework "%s". Supported: %s',
                $this->tool->name,
                $provider,
                implode(', ', $toolFrameworks),
            ));
        }
    }
}
