<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentSwarmAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentSwarm as AgentSwarmData;
use Kanvas\Intelligence\Agents\Enums\AgentSwarmStatusEnum;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Tests\TestCase;

class SwarmActiveMissionTest extends TestCase
{
    public function testCreatingMissionLinksToSwarmActiveMissionRelation(): void
    {
        [$app, $company, $user, $swarm] = $this->bootstrap();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Q2 Pipeline Expansion',
                planType: 'workspace_issue',
                user: $user,
                description: 'Convert 500 cold leads.',
                status: PlanStatusEnum::ACTIVE,
                swarm: $swarm,
                isSwarmMission: true,
                impactSummary: '+$1.4M expected pipeline',
            ),
        )->execute();

        $this->assertTrue((bool) $plan->is_swarm_mission);
        $this->assertSame($swarm->getId(), (int) $plan->swarm_id);
        $this->assertSame('+$1.4M expected pipeline', $plan->impact_summary);

        $activeMission = $swarm->fresh()->activeMission;
        $this->assertNotNull($activeMission);
        $this->assertSame($plan->id, $activeMission->id);
    }

    public function testPromotingNewMissionDemotesPriorOne(): void
    {
        [$app, $company, $user, $swarm] = $this->bootstrap();

        $first = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'First mission',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                swarm: $swarm,
                isSwarmMission: true,
            ),
        )->execute();

        $second = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Second mission',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                swarm: $swarm,
                isSwarmMission: true,
            ),
        )->execute();

        // Reload both — first should now be demoted, second should be the active one.
        $this->assertFalse((bool) $first->fresh()->is_swarm_mission, 'Prior mission demoted');
        $this->assertTrue((bool) $second->fresh()->is_swarm_mission, 'New mission flagged');
        $this->assertSame($second->id, $swarm->fresh()->activeMission->id);
    }

    public function testCompletedMissionFallsOutOfActiveMissionRelation(): void
    {
        [$app, $company, $user, $swarm] = $this->bootstrap();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Mission to complete',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                swarm: $swarm,
                isSwarmMission: true,
            ),
        )->execute();

        $this->assertNotNull($swarm->fresh()->activeMission);

        new UpdatePlanAction(
            $plan,
            new PlanData(
                app: $app,
                company: $company,
                title: $plan->title,
                planType: $plan->plan_type,
                status: PlanStatusEnum::DONE,
                swarm: $swarm,
                isSwarmMission: true,
            ),
        )->execute();

        // is_swarm_mission stays true (history retained), but activeMission
        // filters out terminal-status plans → null.
        $this->assertTrue((bool) $plan->fresh()->is_swarm_mission, 'History flag retained');
        $this->assertNull($swarm->fresh()->activeMission);
    }

    public function testStatusPillUsesExplicitColumnWhenSet(): void
    {
        [$app, $company, $user, $swarm] = $this->bootstrap();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Research mission',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                swarm: $swarm,
                isSwarmMission: true,
                statusPill: 'LEARNING',
            ),
        )->execute();

        $this->assertSame('LEARNING', $plan->fresh()->status_pill);
    }

    public function testStatusPillFallsBackToComputedDefaultWhenNull(): void
    {
        [$app, $company, $user, $swarm] = $this->bootstrap();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'No-deadline mission',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                swarm: $swarm,
                isSwarmMission: true,
            ),
        )->execute();

        // Column is null; accessor falls back to computed. With no deadline,
        // the policy returns ON_TRACK.
        $this->assertNull($plan->getRawOriginal('status_pill'));
        $this->assertSame('ON_TRACK', $plan->fresh()->status_pill);
    }

    public function testStatusPillComputesBehindWhenPastDeadline(): void
    {
        [$app, $company, $user, $swarm] = $this->bootstrap();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Late mission',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                deadlineAt: Carbon::now()->subDays(2),
                swarm: $swarm,
                isSwarmMission: true,
            ),
        )->execute();

        $this->assertSame('BEHIND', $plan->fresh()->status_pill);
    }

    public function testCrossSwarmMissionsAreIsolated(): void
    {
        [$app, $company, $user, $swarmA] = $this->bootstrap();
        $swarmB = $this->createSwarm($app, $company, $user);

        $missionA = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Mission A',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                swarm: $swarmA,
                isSwarmMission: true,
            ),
        )->execute();

        $missionB = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Mission B',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                swarm: $swarmB,
                isSwarmMission: true,
            ),
        )->execute();

        // Promoting B's mission must NOT demote A's mission.
        $this->assertTrue((bool) $missionA->fresh()->is_swarm_mission);
        $this->assertTrue((bool) $missionB->fresh()->is_swarm_mission);
        $this->assertSame($missionA->id, $swarmA->fresh()->activeMission->id);
        $this->assertSame($missionB->id, $swarmB->fresh()->activeMission->id);
    }

    public function testSwarmWithoutMissionReturnsNullActiveMission(): void
    {
        [, , , $swarm] = $this->bootstrap();

        $this->assertNull($swarm->fresh()->activeMission);
    }

    public function testActiveMissionResolvesEvenWhenLaterNonMissionPlanLinksToSwarm(): void
    {
        [$app, $company, $user, $swarm] = $this->bootstrap();

        // Mission created first
        $mission = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'The mission',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                swarm: $swarm,
                isSwarmMission: true,
            ),
        )->execute();

        // Later, a non-mission plan also gets linked to the swarm
        // (e.g., sub-task or unrelated draft tied to the swarm). Its id
        // is higher than the mission's. latestOfMany would pick it,
        // wheres would fail, relation would return null. With the fix,
        // the mission is still returned correctly.
        new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Some draft for the swarm',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
                swarm: $swarm,
                isSwarmMission: false,
            ),
        )->execute();

        $active = $swarm->fresh()->activeMission;
        $this->assertNotNull($active, 'Mission should resolve despite later non-mission plan');
        $this->assertSame($mission->id, $active->id);
    }

    /**
     * @return array{0: Apps, 1: \Kanvas\Companies\Models\Companies, 2: \Kanvas\Users\Models\Users, 3: AgentSwarm}
     */
    private function bootstrap(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $swarm = $this->createSwarm($app, $company, $user);

        return [$app, $company, $user, $swarm];
    }

    private function createSwarm(Apps $app, $company, $user): AgentSwarm
    {
        return new CreateAgentSwarmAction(
            new AgentSwarmData(
                name: 'ActiveMission test swarm · ' . uniqid(),
                description: 'tmp',
                status: AgentSwarmStatusEnum::ACTIVE,
                config: null,
                app: $app,
                company: $company,
                user: $user,
            ),
        )->execute();
    }
}
