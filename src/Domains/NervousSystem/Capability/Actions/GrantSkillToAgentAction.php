<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\Skill;

/**
 * Grant a Skill to an Agent. Enforces the framework-compatibility invariant:
 * the skill's `frameworks` array must include the agent's
 * `agent_type.provider` value, otherwise the grant is rejected at boundary.
 */
class GrantSkillToAgentAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly Skill $skill,
        protected readonly ?int $grantedByUserId = null,
        protected readonly ?Carbon $expiresAt = null,
        protected readonly ?array $config = null,
    ) {
    }

    public function execute(): AgentSkill
    {
        $this->validateProviderCompatibility();

        return DB::connection('intelligence')->transaction(function (): AgentSkill {
            $existing = AgentSkill::query()
                ->where('agent_id', $this->agent->getId())
                ->where('skill_id', $this->skill->getId())
                ->where('is_deleted', 0)
                ->first();

            if ($existing instanceof AgentSkill) {
                $existing->is_active = true;
                $existing->granted_by_users_id = $this->grantedByUserId;
                $existing->granted_at = Carbon::now();
                $existing->expires_at = $this->expiresAt;
                $existing->config = $this->config;
                $existing->saveOrFail();
                $grant = $existing;
            } else {
                $grant = new AgentSkill();
                $grant->apps_id = $this->agent->apps_id;
                $grant->companies_id = $this->agent->companies_id;
                $grant->agent_id = $this->agent->getId();
                $grant->skill_id = $this->skill->getId();
                $grant->granted_by_users_id = $this->grantedByUserId;
                $grant->granted_at = Carbon::now();
                $grant->expires_at = $this->expiresAt;
                $grant->is_active = true;
                $grant->config = $this->config;
                $grant->saveOrFail();
            }

            $grant->emitLedgerEvent('skill.granted', payload: [
                'agent_id' => $grant->agent_id,
                'skill_id' => $grant->skill_id,
                'skill_name' => $this->skill->name,
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

        /** @var array<int, string> $skillFrameworks */
        $skillFrameworks = $this->skill->frameworks;

        if (! in_array($provider, $skillFrameworks, true)) {
            throw new ValidationException(sprintf(
                'Skill "%s" does not support framework "%s". Supported: %s',
                $this->skill->name,
                $provider,
                implode(', ', $skillFrameworks),
            ));
        }
    }
}
