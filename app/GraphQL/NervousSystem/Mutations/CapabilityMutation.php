<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\AttachToolToAgentTypeAction;
use Kanvas\NervousSystem\Capability\Actions\CreateSkillAction;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\Actions\DetachToolFromAgentTypeAction;
use Kanvas\NervousSystem\Capability\Actions\GrantSkillToAgentAction;
use Kanvas\NervousSystem\Capability\Actions\RevokeSkillFromAgentAction;
use Kanvas\NervousSystem\Capability\Actions\SetAgentToolAction;
use Kanvas\NervousSystem\Capability\Actions\UpdateSkillAction;
use Kanvas\NervousSystem\Capability\Actions\UpdateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Skill as SkillData;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Skill;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Models\ToolCategory;
use RuntimeException;

class CapabilityMutation
{
    use ResolvesActingContext;

    public function createSkill(mixed $rootValue, array $request): Skill
    {
        $ctx = $this->actingContext();

        return new CreateSkillAction(
            SkillData::fromMultiple($ctx->app, $request['input']),
            actorUserId: $ctx->user->getId(),
        )->execute();
    }

    public function updateSkill(mixed $rootValue, array $request): Skill
    {
        $ctx = $this->actingContext();

        /** @var Skill $skill */
        $skill = Skill::query()
            ->where('id', (int) $request['id'])
            ->fromApp($ctx->app)
            ->firstOrFail();

        return new UpdateSkillAction(
            $skill,
            SkillData::forUpdate($skill, $ctx->app, $request['input']),
            actorUserId: $ctx->user->getId(),
        )->execute();
    }

    public function createTool(mixed $rootValue, array $request): Tool
    {
        $ctx = $this->actingContext();

        return new CreateToolAction(
            ToolData::fromMultiple($ctx->app, $request['input']),
            actorUserId: $ctx->user->getId(),
        )->execute();
    }

    public function updateTool(mixed $rootValue, array $request): Tool
    {
        $ctx = $this->actingContext();

        /** @var Tool $tool */
        $tool = Tool::query()
            ->where('id', (int) $request['id'])
            ->fromApp($ctx->app)
            ->firstOrFail();

        return new UpdateToolAction(
            $tool,
            ToolData::forUpdate($tool, $ctx->app, $request['input']),
            actorUserId: $ctx->user->getId(),
        )->execute();
    }

    public function grantSkill(mixed $rootValue, array $request): AgentSkill
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $ctx->company, $ctx->app);

        /** @var Skill $skill */
        $skill = Skill::query()
            ->where('id', (int) $request['skill_id'])
            ->fromApp($ctx->app)
            ->firstOrFail();

        return new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: $ctx->user->getId(),
            expiresAt: isset($input['expires_at']) ? Carbon::parse((string) $input['expires_at']) : null,
            config: $input['config'] ?? null,
        )->execute();
    }

    public function revokeSkill(mixed $rootValue, array $request): AgentSkill
    {
        $ctx = $this->actingContext();

        /** @var AgentSkill $grant */
        $grant = AgentSkill::query()
            ->where('id', (int) $request['grant_id'])
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->firstOrFail();

        return new RevokeSkillFromAgentAction(
            grant: $grant,
            actorUserId: $ctx->user->getId(),
            reason: $request['reason'] ?? null,
        )->execute();
    }

    public function attachToolToAgentType(mixed $rootValue, array $request): Tool
    {
        $ctx = $this->actingContext();

        /** @var Tool $tool */
        $tool = Tool::query()
            ->where('id', (int) $request['tool_id'])
            ->fromAppOrGlobal($ctx->app)
            ->firstOrFail();

        /** @var AgentType $agentType */
        $agentType = AgentType::getById((int) $request['agent_type_id'], $ctx->app);

        return new AttachToolToAgentTypeAction($tool, $agentType)->execute();
    }

    public function detachToolFromAgentType(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var Tool $tool */
        $tool = Tool::query()
            ->where('id', (int) $request['tool_id'])
            ->fromAppOrGlobal($ctx->app)
            ->firstOrFail();

        /** @var AgentType $agentType */
        $agentType = AgentType::getById((int) $request['agent_type_id'], $ctx->app);

        return new DetachToolFromAgentTypeAction($tool, $agentType)->execute();
    }

    /**
     * Idempotent per-agent tool toggle. enabled=true grants (or reactivates
     * an existing grant); enabled=false revokes the existing grant if any.
     * No-op when called with the state the agent is already in.
     */
    public function setAgentTool(mixed $rootValue, array $request): ?AgentTool
    {
        $ctx = $this->actingContext();
        $enabled = (bool) $request['enabled'];
        $config = $request['config'] ?? null;

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $ctx->company, $ctx->app);

        // ModelNotFoundException would surface as a generic "Internal server error" through Lighthouse.
        $tool = Tool::query()
            ->where('id', (int) $request['tool_id'])
            ->fromAppOrGlobal($ctx->app)
            ->first();
        if ($tool === null) {
            throw new ValidationException(sprintf(
                'Tool #%d is not available in this app.',
                (int) $request['tool_id'],
            ));
        }

        return new SetAgentToolAction(
            agent: $agent,
            tool: $tool,
            enabled: $enabled,
            actor: $ctx->user,
            config: $config,
        )->execute();
    }

    public function createToolCategory(mixed $rootValue, array $request): ToolCategory
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        return ToolCategory::create([
            'apps_id' => $ctx->app->getId(),
            'slug' => (string) $input['slug'],
            'name' => (string) $input['name'],
            'description' => $input['description'] ?? null,
            'icon' => $input['icon'] ?? null,
            'display_order' => (int) ($input['display_order'] ?? 100),
            'is_active' => (bool) ($input['is_active'] ?? true),
            'is_deleted' => false,
        ]);
    }

    public function updateToolCategory(mixed $rootValue, array $request): ToolCategory
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        // App owns this row — platform globals (apps_id=0) are read-only.
        $category = ToolCategory::query()
            ->where('id', (int) $request['id'])
            ->where('apps_id', $ctx->app->getId())
            ->firstOrFail();

        $category->update(array_filter([
            'name' => $input['name'] ?? null,
            'description' => $input['description'] ?? null,
            'icon' => $input['icon'] ?? null,
            'display_order' => isset($input['display_order']) ? (int) $input['display_order'] : null,
            'is_active' => isset($input['is_active']) ? (bool) $input['is_active'] : null,
        ], fn ($v) => $v !== null));

        $reloaded = $category->fresh();
        if ($reloaded === null) {
            throw new RuntimeException('Tool category vanished between update and reload');
        }

        return $reloaded;
    }
}
