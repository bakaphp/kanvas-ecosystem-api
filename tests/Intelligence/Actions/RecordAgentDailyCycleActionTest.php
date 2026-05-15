<?php

declare(strict_types=1);

namespace Tests\Intelligence\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\RecordAgentDailyCycleAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

class RecordAgentDailyCycleActionTest extends TestCase
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
            ]);
    }

    private function seedEvent(Agent $agent, string $eventType, Carbon $at, array $payload = [], string $status = 'success'): void
    {
        DB::connection('intelligence')->table('nervous_system_events')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'source_domain' => 'Intelligence',
            'event_type' => $eventType,
            'actor_type' => 'Agent',
            'actor_id' => $agent->getId(),
            'status' => $status,
            'payload' => json_encode($payload),
            'payload_schema_version' => 1,
            'occurred_at' => $at,
            'indexed_at' => $at,
        ]);
    }

    public function testCycleAggregatesEventCountsAndProactiveActions(): void
    {
        $agent = $this->makeAgent();
        $today = Carbon::today();

        // Three initiated actions during the day.
        $this->seedEvent($agent, 'agent.initiated.lead_assignment', $today->copy()->setTime(8, 0));
        $this->seedEvent($agent, 'agent.initiated.lead_routing', $today->copy()->setTime(9, 0));
        $this->seedEvent($agent, 'agent.initiated.followup_drafted', $today->copy()->setTime(10, 0));
        // One observation (counts toward events_processed, not proactive).
        $this->seedEvent($agent, 'agent.observed.signal', $today->copy()->setTime(11, 0));

        $cycle = new RecordAgentDailyCycleAction($agent, $today)->execute();

        $this->assertSame(3, $cycle->proactive_actions_count);
        $this->assertSame(4, $cycle->events_processed_count);
        $this->assertSame($agent->apps_id, $cycle->apps_id);
        $this->assertSame($agent->companies_id, $cycle->companies_id);
    }

    public function testCycleCapturesSleepWakeWindowAndPhases(): void
    {
        $agent = $this->makeAgent();
        $today = Carbon::today();

        // Slept at 23:14 yesterday, woke at 05:36 today, with 3 phase transitions.
        $sleptAt = $today->copy()->subDay()->setTime(23, 14);
        $wokeAt = $today->copy()->setTime(5, 36);

        $this->seedEvent($agent, 'agent.slept', $sleptAt);
        $this->seedEvent($agent, 'agent.sleep.phase_started', $sleptAt, ['phase' => 'light', 'outcome' => 'Tagging recent traces']);
        $this->seedEvent($agent, 'agent.sleep.phase_started', $sleptAt->copy()->addMinutes(72), ['phase' => 'deep', 'outcome' => 'Consolidating 89 → 12 patterns']);
        $this->seedEvent($agent, 'agent.sleep.phase_started', $sleptAt->copy()->addMinutes(168), ['phase' => 'dream_rem', 'outcome' => '7 hypotheses generated']);
        $this->seedEvent($agent, 'agent.woke', $wokeAt);

        $cycle = new RecordAgentDailyCycleAction($agent, $today)->execute();

        $this->assertNotNull($cycle->sleep_started_at);
        $this->assertNotNull($cycle->sleep_ended_at);
        $this->assertGreaterThan(0, $cycle->sleep_duration_minutes);
        $this->assertCount(3, $cycle->phases);
        $this->assertSame('light', $cycle->phases[0]->phase);
        $this->assertSame('deep', $cycle->phases[1]->phase);
        $this->assertSame('dream_rem', $cycle->phases[2]->phase);
        $this->assertSame('Tagging recent traces', $cycle->phases[0]->outcome_summary);
    }

    public function testIsIdempotent(): void
    {
        $agent = $this->makeAgent();
        $today = Carbon::today();

        $this->seedEvent($agent, 'agent.initiated.lead_assignment', $today->copy()->setTime(8, 0));
        $this->seedEvent($agent, 'agent.sleep.phase_started', $today->copy()->setTime(1, 0), ['phase' => 'light']);

        $first = new RecordAgentDailyCycleAction($agent, $today)->execute();
        $second = new RecordAgentDailyCycleAction($agent, $today)->execute();

        $this->assertSame($first->id, $second->id, 'Re-running should overwrite the same row, not create a duplicate');

        $rowCount = DB::connection('intelligence')->table('agent_daily_cycles')
            ->where('agent_id', $agent->getId())
            ->where('cycle_date', $today->toDateString())
            ->count();
        $this->assertSame(1, $rowCount);

        $phaseCount = DB::connection('intelligence')->table('agent_sleep_phases')
            ->where('agent_daily_cycle_id', $first->id)
            ->count();
        $this->assertSame(1, $phaseCount, 'Phases should be rebuilt, not duplicated');
    }

    public function testEmptyDayProducesValidCycleWithQuietBriefing(): void
    {
        $agent = $this->makeAgent();
        $today = Carbon::today();

        $cycle = new RecordAgentDailyCycleAction($agent, $today)->execute();

        $this->assertSame(0, $cycle->events_processed_count);
        $this->assertSame(0, $cycle->proactive_actions_count);
        $this->assertStringContainsString('Quiet cycle', (string) $cycle->morning_briefing);
        $this->assertCount(0, $cycle->phases);
    }

    public function testEventsPerHourDerivesFromEventCountAndAwakeMinutes(): void
    {
        $agent = $this->makeAgent();
        $today = Carbon::today();

        // 10 work events + the 2 wake/sleep transition events = 12 total
        // ledger rows over 3 awake hours → 4.0 events/h.
        $wokeAt = $today->copy()->setTime(8, 0);
        $sleptAt = $today->copy()->setTime(11, 0);
        $this->seedEvent($agent, 'agent.woke', $wokeAt);
        $this->seedEvent($agent, 'agent.slept', $sleptAt);
        for ($i = 0; $i < 10; $i++) {
            $this->seedEvent($agent, 'agent.observed.signal', $wokeAt->copy()->addMinutes($i * 15));
        }

        $cycle = new RecordAgentDailyCycleAction($agent, $today)->execute();

        $this->assertGreaterThan(0, $cycle->awake_duration_minutes);
        $this->assertSame(4.0, $cycle->eventsPerHour());
    }

    public function testEventsPerHourIsZeroForAQuietCycle(): void
    {
        $agent = $this->makeAgent();
        $cycle = new RecordAgentDailyCycleAction($agent, Carbon::today())->execute();

        $this->assertSame(0.0, $cycle->eventsPerHour());
    }

    public function testSkillsAreExtractedFromLedger(): void
    {
        $agent = $this->makeAgent();
        $today = Carbon::today();

        $this->seedEvent($agent, 'agent.skill.emerged', $today->copy()->setTime(4, 30), [
            'name' => 'cold-lead re-engagement timing',
            'confidence' => 0.04,
        ]);

        $cycle = new RecordAgentDailyCycleAction($agent, $today)->execute();

        $this->assertCount(1, $cycle->skills_emerged);
        $this->assertSame('cold-lead re-engagement timing', $cycle->skills_emerged[0]['name']);
        $this->assertGreaterThan(0, (float) $cycle->self_improvement_score);
        $this->assertStringContainsString('cold-lead re-engagement timing', (string) $cycle->morning_briefing);
    }
}
