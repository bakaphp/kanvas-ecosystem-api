<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Mutations\NervousSystem;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Actions\CreateSkillAction;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\Actions\GrantSkillToAgentAction;
use Kanvas\NervousSystem\Capability\Actions\GrantToolToAgentAction;
use Kanvas\NervousSystem\Capability\Actions\RevokeSkillFromAgentAction;
use Kanvas\NervousSystem\Capability\Actions\RevokeToolFromAgentAction;
use Kanvas\NervousSystem\Capability\Actions\UpdateSkillAction;
use Kanvas\NervousSystem\Capability\Actions\UpdateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Skill as SkillData;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\SkillTypeEnum;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Skill;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;

class CapabilityMutation
{
    public function createSkill(mixed $rootValue, array $request): Skill
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $input = $request['input'];

        return new CreateSkillAction(
            new SkillData(
                app: $app,
                name: (string) $input['name'],
                frameworks: array_map('strval', (array) $input['frameworks']),
                skillType: isset($input['skill_type'])
                    ? SkillTypeEnum::from((string) $input['skill_type'])
                    : SkillTypeEnum::SYSTEM,
                description: $input['description'] ?? null,
                handler: $input['handler'] ?? null,
                definition: $input['definition'] ?? null,
                version: (string) ($input['version'] ?? '1.0.0'),
                isActive: (bool) ($input['is_active'] ?? true),
            ),
            actorUserId: $user->getId(),
        )->execute();
    }

    public function updateSkill(mixed $rootValue, array $request): Skill
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $input = $request['input'];

        /** @var Skill $skill */
        $skill = Skill::query()
            ->where('id', (int) $request['id'])
            ->forApp((int) $app->getId())
            ->firstOrFail();

        return new UpdateSkillAction(
            $skill,
            new SkillData(
                app: $app,
                name: (string) $input['name'],
                frameworks: array_map('strval', (array) $input['frameworks']),
                skillType: isset($input['skill_type'])
                    ? SkillTypeEnum::from((string) $input['skill_type'])
                    : SkillTypeEnum::from($skill->skill_type),
                description: $input['description'] ?? $skill->description,
                handler: $input['handler'] ?? $skill->handler,
                definition: $input['definition'] ?? $skill->definition,
                version: (string) ($input['version'] ?? $skill->version),
                isActive: (bool) ($input['is_active'] ?? $skill->is_active),
            ),
            actorUserId: $user->getId(),
        )->execute();
    }

    public function createTool(mixed $rootValue, array $request): Tool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $input = $request['input'];

        return new CreateToolAction(
            new ToolData(
                app: $app,
                name: (string) $input['name'],
                frameworks: array_map('strval', (array) $input['frameworks']),
                toolType: isset($input['tool_type'])
                    ? ToolTypeEnum::from((string) $input['tool_type'])
                    : ToolTypeEnum::SYSTEM,
                description: $input['description'] ?? null,
                handler: $input['handler'] ?? null,
                inputSchema: $input['input_schema'] ?? null,
                outputSchema: $input['output_schema'] ?? null,
                requiresPermission: $input['requires_permission'] ?? null,
                version: (string) ($input['version'] ?? '1.0.0'),
                isActive: (bool) ($input['is_active'] ?? true),
            ),
            actorUserId: $user->getId(),
        )->execute();
    }

    public function updateTool(mixed $rootValue, array $request): Tool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $input = $request['input'];

        /** @var Tool $tool */
        $tool = Tool::query()
            ->where('id', (int) $request['id'])
            ->forApp((int) $app->getId())
            ->firstOrFail();

        return new UpdateToolAction(
            $tool,
            new ToolData(
                app: $app,
                name: (string) $input['name'],
                frameworks: array_map('strval', (array) $input['frameworks']),
                toolType: isset($input['tool_type'])
                    ? ToolTypeEnum::from((string) $input['tool_type'])
                    : ToolTypeEnum::from($tool->tool_type),
                description: $input['description'] ?? $tool->description,
                handler: $input['handler'] ?? $tool->handler,
                inputSchema: $input['input_schema'] ?? $tool->input_schema,
                outputSchema: $input['output_schema'] ?? $tool->output_schema,
                requiresPermission: $input['requires_permission'] ?? $tool->requires_permission,
                version: (string) ($input['version'] ?? $tool->version),
                isActive: (bool) ($input['is_active'] ?? $tool->is_active),
            ),
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
            ->forApp((int) $app->getId())
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

    public function grantTool(mixed $rootValue, array $request): AgentTool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $input['agent_id'], $company, $app);

        /** @var Tool $tool */
        $tool = Tool::query()
            ->where('id', (int) $request['tool_id'])
            ->forApp((int) $app->getId())
            ->firstOrFail();

        return new GrantToolToAgentAction(
            agent: $agent,
            tool: $tool,
            grantedByUserId: $user->getId(),
            expiresAt: isset($input['expires_at']) ? Carbon::parse((string) $input['expires_at']) : null,
            config: $input['config'] ?? null,
        )->execute();
    }

    public function revokeTool(mixed $rootValue, array $request): AgentTool
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var AgentTool $grant */
        $grant = AgentTool::query()
            ->where('id', (int) $request['grant_id'])
            ->fromApp($app)
            ->fromCompany($company)
            ->firstOrFail();

        return new RevokeToolFromAgentAction(
            grant: $grant,
            actorUserId: $user->getId(),
            reason: $request['reason'] ?? null,
        )->execute();
    }
}
