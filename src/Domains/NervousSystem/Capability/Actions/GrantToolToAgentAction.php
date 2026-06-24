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
            // The (agent_id, tool_id, is_deleted) unique key allows two rows per pair (one active + one soft-deleted), so legacy duplicates can collide on the next flip. Hard-delete siblings to keep at most one row.
            $rows = AgentTool::withTrashed()
                ->where('agent_id', $this->agent->getId())
                ->where('tool_id', $this->tool->getId())
                ->orderBy('id', 'desc')
                ->get();

            /** @var AgentTool|null $existing */
            $existing = $rows->first();
            if ($rows->count() > 1) {
                AgentTool::withTrashed()
                    ->whereIn('id', $rows->skip(1)->pluck('id')->all())
                    ->forceDelete();
            }

            $attributes = [
                'apps_id' => $this->agent->apps_id,
                'companies_id' => $this->agent->companies_id,
                'agent_id' => $this->agent->getId(),
                'tool_id' => $this->tool->getId(),
                'granted_by_users_id' => $this->grantedByUserId,
                'granted_at' => Carbon::now(),
                'expires_at' => $this->expiresAt,
                'is_active' => true,
                'is_deleted' => false,
                'config' => $this->config,
            ];

            if ($existing instanceof AgentTool) {
                $existing->fill($attributes)->saveOrFail();
                $grant = $existing;
            } else {
                $grant = AgentTool::create($attributes);
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
