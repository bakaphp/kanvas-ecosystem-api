<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\RebuildAgentToolInstructionsAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\AttachToolToAgentTypeAction;
use Kanvas\NervousSystem\Capability\Actions\CreateSkillAction;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\Actions\DetachToolFromAgentTypeAction;
use Kanvas\NervousSystem\Capability\Actions\GrantSkillToAgentAction;
use Kanvas\NervousSystem\Capability\Actions\GrantToolToAgentAction;
use Kanvas\NervousSystem\Capability\Actions\RevokeSkillFromAgentAction;
use Kanvas\NervousSystem\Capability\Actions\RevokeToolFromAgentAction;
use Kanvas\NervousSystem\Capability\Actions\UpdateSkillAction;
use Kanvas\NervousSystem\Capability\Actions\UpdateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Skill as SkillData;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Skill;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Models\ToolCategory;
use Kanvas\Users\Models\Users;
use RuntimeException;

class CapabilityMutation
{
    public function createSkill(mixed $rootValue, array $request): Skill
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return new CreateSkillAction(
            SkillData::fromMultiple($app, $request['input']),
            actorUserId: $user->getId(),
        )->execute();
    }

    public function updateSkill(mixed $rootValue, array $request): Skill
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        /** @var Skill $skill */
        $skill = Skill::query()
            ->where('id', (int) $request['id'])
            ->fromApp($app)
            ->firstOrFail();

        return new UpdateSkillAction(
            $skill,
            SkillData::forUpdate($skill, $app, $request['input']),
            actorUserId: $user->getId(),
        )->execute();
    }

    public function createTool(mixed $rootValue, array $request): Tool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return new CreateToolAction(
            ToolData::fromMultiple($app, $request['input']),
            actorUserId: $user->getId(),
        )->execute();
    }

    public function updateTool(mixed $rootValue, array $request): Tool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        /** @var Tool $tool */
        $tool = Tool::query()
            ->where('id', (int) $request['id'])
            ->fromApp($app)
            ->firstOrFail();

        return new UpdateToolAction(
            $tool,
            ToolData::forUpdate($tool, $app, $request['input']),
            actorUserId: $user->getId(),
        )->execute();
    }

    public function grantSkill(mixed $rootValue, array $request): AgentSkill
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app);

        /** @var Skill $skill */
        $skill = Skill::query()
            ->where('id', (int) $request['skill_id'])
            ->fromApp($app)
            ->firstOrFail();

        return new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: $user->getId(),
            expiresAt: isset($input['expires_at']) ? Carbon::parse((string) $input['expires_at']) : null,
            config: $input['config'] ?? null,
        )->execute();
    }

    public function revokeSkill(mixed $rootValue, array $request): AgentSkill
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var AgentSkill $grant */
        $grant = AgentSkill::query()
            ->where('id', (int) $request['grant_id'])
            ->fromApp($app)
            ->fromCompany($company)
            ->firstOrFail();

        return new RevokeSkillFromAgentAction(
            grant: $grant,
            actorUserId: $user->getId(),
            reason: $request['reason'] ?? null,
        )->execute();
    }

    public function attachToolToAgentType(mixed $rootValue, array $request): Tool
    {
        $app = app(Apps::class);

        /** @var Tool $tool */
        $tool = Tool::query()
            ->where('id', (int) $request['tool_id'])
            ->fromAppOrGlobal($app)
            ->firstOrFail();

        /** @var AgentType $agentType */
        $agentType = AgentType::getById((int) $request['agent_type_id'], $app);

        return new AttachToolToAgentTypeAction($tool, $agentType)->execute();
    }

    public function detachToolFromAgentType(mixed $rootValue, array $request): bool
    {
        $app = app(Apps::class);

        /** @var Tool $tool */
        $tool = Tool::query()
            ->where('id', (int) $request['tool_id'])
            ->fromAppOrGlobal($app)
            ->firstOrFail();

        /** @var AgentType $agentType */
        $agentType = AgentType::getById((int) $request['agent_type_id'], $app);

        return new DetachToolFromAgentTypeAction($tool, $agentType)->execute();
    }

    /**
     * Idempotent per-agent tool toggle. enabled=true grants (or reactivates
     * an existing grant); enabled=false revokes the existing grant if any.
     * No-op when called with the state the agent is already in.
     */
    public function setAgentTool(mixed $rootValue, array $request): ?AgentTool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $enabled = (bool) $request['enabled'];
        $config = $request['config'] ?? null;

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $request['agent_id'], $company, $app);

        // ModelNotFoundException would surface as a generic "Internal server error" through Lighthouse.
        $tool = Tool::query()
            ->where('id', (int) $request['tool_id'])
            ->fromAppOrGlobal($app)
            ->first();
        if ($tool === null) {
            throw new ValidationException(sprintf(
                'Tool #%d is not available in this app.',
                (int) $request['tool_id'],
            ));
        }

        // withTrashed so soft-deleted rows are visible: toggling off then on must reactivate the same row, not insert a duplicate.
        $existing = AgentTool::query()
            ->withTrashed()
            ->where('agent_id', $agent->getId())
            ->where('tool_id', $tool->getId())
            ->first();

        if ($enabled) {
            $grant = new GrantToolToAgentAction(
                agent: $agent,
                tool: $tool,
                grantedByUserId: $user->getId(),
                config: $config,
            )->execute();

            $agent->selectedTools()->syncWithoutDetaching([$tool->getId()]);
            new RebuildAgentToolInstructionsAction($agent, $app)->execute();

            return $grant;
        }

        $agent->selectedTools()->detach($tool->getId());
        new RebuildAgentToolInstructionsAction($agent, $app)->execute();

        if ($existing !== null && $existing->is_deleted) {
            return $existing;
        }

        if ($existing === null) {
            // Tool was only "selected" via the agent type's defaults — persist an explicit revocation so the read query subtracts it.
            return AgentTool::create([
                'apps_id' => $agent->apps_id,
                'companies_id' => $agent->companies_id,
                'agent_id' => $agent->getId(),
                'tool_id' => $tool->getId(),
                'granted_by_users_id' => $user->getId(),
                'granted_at' => Carbon::now(),
                'is_active' => false,
                'is_deleted' => true,
                'config' => $config,
            ]);
        }

        return new RevokeToolFromAgentAction(
            grant: $existing,
            actorUserId: $user->getId(),
        )->execute();
    }

    public function createToolCategory(mixed $rootValue, array $request): ToolCategory
    {
        $app = app(Apps::class);
        $input = $request['input'];

        return ToolCategory::create([
            'apps_id' => $app->getId(),
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
        $app = app(Apps::class);
        $input = $request['input'];

        // App owns this row — platform globals (apps_id=0) are read-only.
        $category = ToolCategory::query()
            ->where('id', (int) $request['id'])
            ->where('apps_id', $app->getId())
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
