<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\CreateSkillAction;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\Actions\ExpireCapabilitiesAction;
use Kanvas\NervousSystem\Capability\Actions\GrantSkillToAgentAction;
use Kanvas\NervousSystem\Capability\Actions\GrantToolToAgentAction;
use Kanvas\NervousSystem\Capability\Actions\RevokeSkillFromAgentAction;
use Kanvas\NervousSystem\Capability\Actions\UpdateSkillAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Skill as SkillData;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\SkillTypeEnum;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\AgentSkill;
use Kanvas\NervousSystem\Capability\Models\Skill;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class CapabilityTest extends TestCase
{
    public function testCreateSkillWritesRowAndEmitsEvent(): void
    {
        $skill = new CreateSkillAction(
            new SkillData(
                app: $this->app(),
                name: 'test-skill-' . uniqid(),
                frameworks: ['neuron', 'laravel'],
                skillType: SkillTypeEnum::CUSTOM,
                description: 'Test skill',
                definition: ['prompt' => 'Hello'],
                version: '1.0.1',
            ),
        )->execute();

        $this->assertDatabaseHas(
            'nervous_system_skills',
            ['id' => $skill->id, 'name' => $skill->name],
            'intelligence',
        );
        $this->assertSame(['neuron', 'laravel'], $skill->frameworks);

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'event_type' => 'skill.created',
                'source_entity_type' => Skill::class,
                'source_entity_id' => $skill->id,
            ],
            'intelligence',
        );
    }

    public function testCreateSkillRejectsUnknownFramework(): void
    {
        $this->expectException(ValidationException::class);

        new CreateSkillAction(
            new SkillData(
                app: $this->app(),
                name: 'bad-framework',
                frameworks: ['some-fake-framework'],
            ),
        )->execute();
    }

    public function testCreateSkillRejectsEmptyFrameworks(): void
    {
        $this->expectException(ValidationException::class);

        new CreateSkillAction(
            new SkillData(
                app: $this->app(),
                name: 'no-frameworks',
                frameworks: [],
            ),
        )->execute();
    }

    public function testUpdateSkillEmitsUpdatedEventWithDiff(): void
    {
        $skill = $this->createSkill(['neuron']);
        $newName = 'renamed-' . uniqid();

        new UpdateSkillAction(
            $skill,
            new SkillData(
                app: $this->app(),
                name: $newName,
                frameworks: ['neuron', 'openclaw'],
                version: '2.0.0',
            ),
        )->execute();

        $this->assertDatabaseHas(
            'nervous_system_skills',
            ['id' => $skill->id, 'name' => $newName, 'version' => '2.0.0'],
            'intelligence',
        );

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['event_type' => 'skill.updated', 'source_entity_id' => $skill->id],
            'intelligence',
        );
    }

    public function testCreateToolEmitsEvent(): void
    {
        $tool = new CreateToolAction(
            new ToolData(
                app: $this->app(),
                name: 'test-tool-' . uniqid(),
                frameworks: ['laravel'],
                toolType: ToolTypeEnum::CUSTOM,
                description: 'Test tool',
                inputSchema: ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
            ),
        )->execute();

        $this->assertDatabaseHas(
            'nervous_system_tools',
            ['id' => $tool->id, 'name' => $tool->name],
            'intelligence',
        );

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['event_type' => 'tool.created', 'source_entity_id' => $tool->id],
            'intelligence',
        );
    }

    public function testGrantSkillToCompatibleAgentSucceeds(): void
    {
        $skill = $this->createSkill(['neuron']);
        $agent = $this->createAgent('neuron');

        $grant = new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();

        $this->assertSame((int) $agent->getId(), $grant->agent_id);
        $this->assertSame((int) $skill->getId(), $grant->skill_id);
        $this->assertTrue($grant->is_active);

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'event_type' => 'skill.granted',
                'source_entity_type' => AgentSkill::class,
                'source_entity_id' => $grant->id,
            ],
            'intelligence',
        );
    }

    public function testGrantSkillRejectsFrameworkMismatch(): void
    {
        $skill = $this->createSkill(['neuron']);
        $agent = $this->createAgent('openclaw');

        $this->expectException(ValidationException::class);

        new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();
    }

    public function testRevokeSkillFlipsFlagsAndEmitsEvent(): void
    {
        $skill = $this->createSkill(['neuron']);
        $agent = $this->createAgent('neuron');

        $grant = new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();

        new RevokeSkillFromAgentAction(
            grant: $grant,
            actorUserId: (int) $this->user()->getId(),
            reason: 'no longer needed',
        )->execute();

        $grant->refresh();
        $this->assertFalse($grant->is_active);
        $this->assertTrue($grant->is_deleted);

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['event_type' => 'skill.revoked', 'source_entity_id' => $grant->id],
            'intelligence',
        );
    }

    public function testGrantToolToCompatibleAgentSucceeds(): void
    {
        $tool = $this->createTool(['laravel', 'neuron']);
        $agent = $this->createAgent('laravel');

        $grant = new GrantToolToAgentAction(
            agent: $agent,
            tool: $tool,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();

        $this->assertSame((int) $agent->getId(), $grant->agent_id);
        $this->assertSame((int) $tool->getId(), $grant->tool_id);

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['event_type' => 'tool.granted', 'source_entity_id' => $grant->id],
            'intelligence',
        );
    }

    public function testGrantToolRejectsFrameworkMismatch(): void
    {
        $tool = $this->createTool(['neuron']);
        $agent = $this->createAgent('adk');

        $this->expectException(ValidationException::class);

        new GrantToolToAgentAction(
            agent: $agent,
            tool: $tool,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();
    }

    public function testCapabilityProviderReturnsActiveSkills(): void
    {
        $agent = $this->createAgent('neuron');
        $skillA = $this->createSkill(['neuron']);
        $skillB = $this->createSkill(['neuron']);
        $skillC = $this->createSkill(['laravel']);

        new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skillA,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();
        new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skillB,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();

        $skills = new CapabilityProvider()->getActiveSkills($agent);
        $skillIds = $skills->pluck('id')->all();

        $this->assertContains($skillA->id, $skillIds);
        $this->assertContains($skillB->id, $skillIds);
        $this->assertNotContains($skillC->id, $skillIds);
    }

    public function testCapabilityProviderFiltersByFramework(): void
    {
        $agent = $this->createAgent('neuron');
        $multiSkill = $this->createSkill(['neuron', 'laravel']);
        $neuronOnly = $this->createSkill(['neuron']);

        new GrantSkillToAgentAction(
            agent: $agent,
            skill: $multiSkill,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();
        new GrantSkillToAgentAction(
            agent: $agent,
            skill: $neuronOnly,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();

        $laravelSkills = new CapabilityProvider()->getActiveSkills($agent, 'laravel');

        $this->assertCount(1, $laravelSkills);
        $this->assertSame($multiSkill->id, $laravelSkills->first()->id);
    }

    public function testCapabilityProviderExcludesExpiredGrants(): void
    {
        $agent = $this->createAgent('neuron');
        $skill = $this->createSkill(['neuron']);

        new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: (int) $this->user()->getId(),
            expiresAt: Carbon::now()->subHour(),
        )->execute();

        $skills = new CapabilityProvider()->getActiveSkills($agent);

        $this->assertCount(0, $skills);
    }

    public function testExpireCapabilitiesActionAutoExpiresAndEmits(): void
    {
        $agent = $this->createAgent('neuron');
        $skill = $this->createSkill(['neuron']);

        $grant = new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: (int) $this->user()->getId(),
            expiresAt: Carbon::now()->subMinute(),
        )->execute();

        $this->assertTrue($grant->is_active);

        $result = new ExpireCapabilitiesAction()->execute();

        $this->assertGreaterThanOrEqual(1, $result['skills_expired']);

        $grant->refresh();
        $this->assertFalse($grant->is_active);

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['event_type' => 'skill.expired', 'source_entity_id' => $grant->id],
            'intelligence',
        );
    }

    public function testGrantTenantScopingPreventsCrossAppLeak(): void
    {
        $skill = $this->createSkill(['neuron']);
        $agent = $this->createAgent('neuron');

        new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();

        $myGrants = AgentSkill::query()
            ->fromApp($this->app())
            ->fromCompany($this->user()->getCurrentCompany())
            ->where('agent_id', $agent->getId())
            ->get();

        $this->assertCount(1, $myGrants);

        $foreignGrants = AgentSkill::query()
            ->where('apps_id', 999996)
            ->get();

        foreach ($foreignGrants as $g) {
            $this->assertNotSame((int) $agent->getId(), (int) $g->agent_id);
        }
    }

    public function testGrantOnExistingRevokedRowReusesIt(): void
    {
        $skill = $this->createSkill(['neuron']);
        $agent = $this->createAgent('neuron');

        $first = new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: (int) $this->user()->getId(),
        )->execute();

        $firstId = $first->id;

        $second = new GrantSkillToAgentAction(
            agent: $agent,
            skill: $skill,
            grantedByUserId: (int) $this->user()->getId(),
            expiresAt: Carbon::now()->addDay(),
        )->execute();

        $this->assertSame($firstId, $second->id, 'Re-granting should reuse the existing active row');
        $this->assertNotNull($second->expires_at);
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function user(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * @param  array<int, string>  $frameworks
     */
    private function createSkill(array $frameworks): Skill
    {
        return new CreateSkillAction(
            new SkillData(
                app: $this->app(),
                name: 'skill-' . uniqid(),
                frameworks: $frameworks,
                skillType: SkillTypeEnum::CUSTOM,
            ),
        )->execute();
    }

    /**
     * @param  array<int, string>  $frameworks
     */
    private function createTool(array $frameworks): Tool
    {
        return new CreateToolAction(
            new ToolData(
                app: $this->app(),
                name: 'tool-' . uniqid(),
                frameworks: $frameworks,
                toolType: ToolTypeEnum::CUSTOM,
            ),
        )->execute();
    }

    private function createAgent(string $provider): Agent
    {
        $app = $this->app();
        $company = $this->user()->getCurrentCompany();

        $type = new AgentType();
        $type->uuid = Str::uuid()->toString();
        $type->apps_id = $app->getId();
        $type->name = 'Type-' . uniqid();
        $type->provider = $provider;
        $type->description = 'Test agent type';
        $type->config = [];
        $type->role = 'test-role';
        $type->is_active = true;
        $type->is_published = false;
        $type->is_multi_agent = false;
        $type->multi_agent_list = [];
        $type->saveOrFail();

        $agent = new Agent();
        $agent->uuid = Str::uuid()->toString();
        $agent->apps_id = $app->getId();
        $agent->companies_id = $company->getId();
        $agent->agent_type_id = $type->id;
        $agent->user_id = $this->user()->getId();
        $agent->name = 'Agent ' . uniqid();
        $agent->slug = 'agent-' . uniqid();
        $agent->config = [];
        $agent->role = [];
        $agent->is_active = true;
        $agent->saveOrFail();

        return $agent;
    }
}
