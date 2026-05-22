<?php

declare(strict_types=1);

namespace Tests\Intelligence\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\RefreshAgentLiveCountersAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;
use Tests\TestCase;

class RefreshAgentLiveCountersActionTest extends TestCase
{
    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
                'is_active' => true,
                'awake_state' => 'awake',
            ]);
    }

    private function seedCycle(Agent $agent, ?Carbon $awakeAt, ?Carbon $sleptAt = null, array $overrides = []): AgentDailyCycle
    {
        return AgentDailyCycle::create(array_merge([
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agent_id' => $agent->getId(),
            'cycle_date' => Carbon::today()->toDateString(),
            'awake_started_at' => $awakeAt,
            'awake_ended_at' => $sleptAt,
            'sleep_started_at' => null,
            'sleep_ended_at' => $awakeAt,
            'awake_duration_minutes' => 0,
            'sleep_duration_minutes' => 0,
            'proactive_actions_count' => 0,
            'events_processed_count' => 0,
            'morning_briefing' => 'Original briefing — must not be overwritten',
            'proposed_actions' => ['Original action item'],
            'skills_emerged' => [['name' => 'original_skill', 'confidence' => 0.04]],
            'self_improvement_score' => 0.040,
            'signed_by_text' => '— Original signature',
            'is_deleted' => false,
        ], $overrides));
    }

    private function seedEvent(Agent $agent, string $eventType, Carbon $at): void
    {
        DB::connection('intelligence')->table('nervous_system_events')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'source_domain' => 'Intelligence',
            'event_type' => $eventType,
            'actor_type' => 'Agent',
            'actor_id' => $agent->getId(),
            'status' => 'success',
            'payload' => json_encode([]),
            'payload_schema_version' => 1,
            'occurred_at' => $at,
            'indexed_at' => $at,
        ]);
    }

    public function testRefreshUpdatesCountersFromAwakeWindowToNow(): void
    {
        $agent = $this->makeAgent();
        $wokeAt = Carbon::now()->subHours(2);
        $this->seedCycle($agent, awakeAt: $wokeAt);

        $this->seedEvent($agent, 'agent.initiated.lead_assignment', $wokeAt->copy()->addMinutes(10));
        $this->seedEvent($agent, 'agent.initiated.lead_routing', $wokeAt->copy()->addMinutes(60));
        $this->seedEvent($agent, 'agent.observed.signal', $wokeAt->copy()->addMinutes(90));

        $refreshed = new RefreshAgentLiveCountersAction($agent)->execute();

        $this->assertNotNull($refreshed);
        $this->assertSame(3, $refreshed->events_processed_count);
        $this->assertSame(2, $refreshed->proactive_actions_count);
        $this->assertGreaterThanOrEqual(119, $refreshed->awake_duration_minutes);
        $this->assertLessThanOrEqual(121, $refreshed->awake_duration_minutes);
    }

    public function testRefreshDoesNotTouchNarrativeFields(): void
    {
        $agent = $this->makeAgent();
        $cycle = $this->seedCycle($agent, awakeAt: Carbon::now()->subHours(1))->refresh();
        $originalBriefing = $cycle->morning_briefing;
        $originalActions = $cycle->proposed_actions;
        $originalSkills = $cycle->skills_emerged;
        $originalScore = $cycle->self_improvement_score;
        $originalSignature = $cycle->signed_by_text;

        $this->seedEvent($agent, 'agent.initiated.something', Carbon::now()->subMinutes(20));

        $refreshed = new RefreshAgentLiveCountersAction($agent)->execute();

        $this->assertNotNull($refreshed);
        $this->assertSame($originalBriefing, $refreshed->morning_briefing);
        $this->assertSame($originalActions, $refreshed->proposed_actions);
        $this->assertSame($originalSkills, $refreshed->skills_emerged);
        $this->assertSame($originalScore, $refreshed->self_improvement_score);
        $this->assertSame($originalSignature, $refreshed->signed_by_text);
    }

    public function testRefreshReturnsNullWhenTodaysCycleRowDoesNotExist(): void
    {
        $agent = $this->makeAgent();
        // No seedCycle call — daily cron hasn't run yet.

        $result = new RefreshAgentLiveCountersAction($agent)->execute();

        $this->assertNull($result);
    }

    public function testRefreshSkipsAgentsThatHaveNotWokenYet(): void
    {
        $agent = $this->makeAgent();
        $this->seedCycle($agent, awakeAt: null);

        $this->seedEvent($agent, 'agent.initiated.foo', Carbon::now()->subMinutes(5));

        $result = new RefreshAgentLiveCountersAction($agent)->execute();

        $this->assertNotNull($result);
        $this->assertSame(0, $result->events_processed_count, 'No window means no counts should change');
        $this->assertSame(0, $result->awake_duration_minutes);
    }

    public function testRefreshCapturesLastActionFromTheMostRecentEvent(): void
    {
        $agent = $this->makeAgent();
        $wokeAt = Carbon::now()->subHours(2);
        $this->seedCycle($agent, awakeAt: $wokeAt);

        $this->seedEvent($agent, 'agent.initiated.early', $wokeAt->copy()->addMinutes(5));
        // Most recent — should be the one captured.
        $latestAt = Carbon::now()->subMinutes(7);
        DB::connection('intelligence')->table('nervous_system_events')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'source_domain' => 'CRM',
            'event_type' => 'agent.initiated.lead_followup',
            'actor_type' => 'Agent',
            'actor_id' => $agent->getId(),
            'status' => 'success',
            'payload' => json_encode(['description' => 'Drafted follow-up for cold lead #99']),
            'payload_schema_version' => 1,
            'occurred_at' => $latestAt,
            'indexed_at' => $latestAt,
        ]);

        $refreshed = new RefreshAgentLiveCountersAction($agent)->execute();

        $this->assertNotNull($refreshed);
        $this->assertNotNull($refreshed->last_action_at);
        $this->assertSame($latestAt->format('Y-m-d H:i:s'), $refreshed->last_action_at->format('Y-m-d H:i:s'));
        $this->assertSame('Drafted follow-up for cold lead #99', $refreshed->last_action_label);
    }

    public function testRefreshFallsBackToHumanizedEventTypeWhenNoDescriptionInPayload(): void
    {
        $agent = $this->makeAgent();
        $wokeAt = Carbon::now()->subHours(1);
        $this->seedCycle($agent, awakeAt: $wokeAt);

        $this->seedEvent($agent, 'agent.initiated.lead_assignment', Carbon::now()->subMinutes(3));

        $refreshed = new RefreshAgentLiveCountersAction($agent)->execute();

        $this->assertNotNull($refreshed);
        $this->assertSame('Initiated · lead assignment', $refreshed->last_action_label);
    }

    public function testRefreshLastActionIsNullWhenNoEventsInWindow(): void
    {
        $agent = $this->makeAgent();
        $this->seedCycle($agent, awakeAt: Carbon::now()->subMinutes(15));
        // No events at all.

        $refreshed = new RefreshAgentLiveCountersAction($agent)->execute();

        $this->assertNotNull($refreshed);
        $this->assertNull($refreshed->last_action_at);
        $this->assertNull($refreshed->last_action_label);
    }

    public function testRefreshUsesAwakeEndedAtAsTheUpperBoundWhenAgentHasSlept(): void
    {
        $agent = $this->makeAgent();
        $wokeAt = Carbon::now()->subHours(3);
        $sleptAt = Carbon::now()->subMinutes(30);
        $this->seedCycle($agent, awakeAt: $wokeAt, sleptAt: $sleptAt);

        // Event during the awake window — counts.
        $this->seedEvent($agent, 'agent.initiated.in_window', $wokeAt->copy()->addMinutes(15));
        // Event AFTER awake_ended_at — must NOT count toward the awake-window total.
        $this->seedEvent($agent, 'agent.initiated.post_sleep', $sleptAt->copy()->addMinutes(5));

        $refreshed = new RefreshAgentLiveCountersAction($agent)->execute();

        $this->assertNotNull($refreshed);
        $this->assertSame(1, $refreshed->events_processed_count);
        $this->assertSame(1, $refreshed->proactive_actions_count);
    }
}
