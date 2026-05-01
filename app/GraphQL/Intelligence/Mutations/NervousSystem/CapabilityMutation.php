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
            ->forApp((int) $app->getId())
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
            ->forApp((int) $app->getId())
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
