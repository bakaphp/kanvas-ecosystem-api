<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;
use Kanvas\NervousSystem\DailyLearning\Actions\SummarizeAgentDailyLearningAction;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class SummarizeAgentDailyLearningActionTest extends TestCase
{
    public function testReturnsNullWhenNoConversationsOnCycleDate(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        $cycleDate = Carbon::parse('2026-05-23');

        $result = new SummarizeAgentDailyLearningAction(
            agent: $agent,
            app: $app,
            company: $company,
            cycleDate: $cycleDate,
        )->execute();

        $this->assertNull($result, 'Empty-day cycles should be a no-op, not a fabricated row');
        $this->assertDatabaseMissing(
            'agent_daily_cycles',
            [
                'agent_id' => $agent->getId(),
                'cycle_date' => '2026-05-23',
            ],
            'intelligence',
        );
    }

    public function testDryRunReturnsParsedSummaryWithoutPersisting(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        $cycleDate = Carbon::parse('2026-05-23');

        $this->seedConversationOnCompanyTimezoneDay(
            $agent,
            $app,
            $company,
            $user->getId(),
            $cycleDate,
        );

        StructuredAnonymousAgent::fake([[
            'briefing' => 'Yesterday I helped Kevin with the gate meeting and processed 3 EVT samples.',
            'proposed_actions' => ['Check the schedule master for slipped dates.'],
            'durable_facts' => ['Kevin is the gate-meeting lead.'],
            'skills_emerged' => [['name' => 'gate-meeting-facilitation', 'confidence' => 0.6]],
            'self_improvement_score' => 0.25,
        ]]);

        $summary = new SummarizeAgentDailyLearningAction(
            agent: $agent,
            app: $app,
            company: $company,
            cycleDate: $cycleDate,
            dryRun: true,
        )->execute();

        $this->assertNotNull($summary);
        $this->assertSame('Yesterday I helped Kevin with the gate meeting and processed 3 EVT samples.', $summary->briefing);
        $this->assertSame(['Kevin is the gate-meeting lead.'], $summary->durable_facts);
        $this->assertSame(0.25, $summary->self_improvement_score);

        // dryRun must NOT have persisted the cycle row
        $this->assertDatabaseMissing(
            'agent_daily_cycles',
            [
                'agent_id' => $agent->getId(),
                'cycle_date' => '2026-05-23',
            ],
            'intelligence',
        );
    }

    public function testFullRunPersistsCycleAndEmitsLedgerWhenSkipPushIsTrue(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        $cycleDate = Carbon::parse('2026-05-23');

        $this->seedConversationOnCompanyTimezoneDay(
            $agent,
            $app,
            $company,
            $user->getId(),
            $cycleDate,
        );

        StructuredAnonymousAgent::fake([[
            'briefing' => 'Productive day — onboarded a new client.',
            'proposed_actions' => ['Follow up with Steven on PNP timing.'],
            'durable_facts' => ['Steven handles PNP communications.'],
            'skills_emerged' => [['name' => 'client-onboarding', 'confidence' => 0.7]],
            'self_improvement_score' => 0.3,
        ]]);

        new SummarizeAgentDailyLearningAction(
            agent: $agent,
            app: $app,
            company: $company,
            cycleDate: $cycleDate,
            skipPush: true,
        )->execute();

        $cycle = AgentDailyCycle::query()
            ->where('agent_id', $agent->getId())
            ->where('cycle_date', '2026-05-23')
            ->first();

        $this->assertNotNull($cycle, 'Cycle row must be persisted after a full run');
        $this->assertSame('Productive day — onboarded a new client.', $cycle->morning_briefing);
        $this->assertSame(['Follow up with Steven on PNP timing.'], $cycle->proposed_actions);
        $this->assertSame(['Steven handles PNP communications.'], $cycle->durable_facts);
        $this->assertSame(0.3, (float) $cycle->self_improvement_score);
        $this->assertStringContainsString('felix-sales', (string) $cycle->signed_by_text);

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'event_type' => 'agent.daily_learning.summarized',
                'source_entity_type' => Agent::class,
                'source_entity_id' => $agent->getId(),
            ],
            'intelligence',
        );
    }

    public function testCompanyTimezoneAnchorsTheDayWindow(): void
    {
        // Regression — felix-sales smoke caught it. Carbon::parse(YMD) is
        // UTC by default; setTimezone() then shifts the moment instead of
        // reinterpreting the day, sliding the window backward 4h for ET.
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $originalTimezone = $company->timezone;
        $company->timezone = 'America/New_York';
        // No ->save() — the action reads $this->company->timezone from the
        // model instance, so in-memory mutation is enough. Persisting would
        // leak the change to subsequent tests outside any transaction.

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        // A message stamped 04:30 UTC on May 18 → 00:30 EDT on May 18.
        // Belongs to the May 18 NY day; must NOT bleed into May 17.
        $conversation = AgentConversation::query()->create([
            'id' => 'conv-tz-' . uniqid('', true),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
            'title' => 'tz-boundary check',
            'meta' => ['source' => 'slack'],
        ]);
        AgentConversationMessage::query()->create([
            'id' => 'msg-tz-' . uniqid('', true),
            'conversation_id' => $conversation->id,
            'user_id' => $user->getId(),
            'agent' => 'TestAgent',
            'role' => 'user',
            'content' => 'Boundary message at 00:30 EDT (04:30 UTC).',
            'attachments' => [], 'tool_calls' => [], 'tool_results' => [],
            'usage' => [], 'meta' => [],
            'created_at' => Carbon::parse('2026-05-18 04:30:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-05-18 04:30:00', 'UTC'),
        ]);

        StructuredAnonymousAgent::fake([[
            'briefing' => 'Captured the boundary message.',
            'proposed_actions' => [],
            'durable_facts' => [],
            'skills_emerged' => [],
            'self_improvement_score' => 0.0,
        ]]);

        $summary = new SummarizeAgentDailyLearningAction(
            agent: $agent,
            app: $app,
            company: $company,
            cycleDate: Carbon::parse('2026-05-18'),
            dryRun: true,
        )->execute();

        $this->assertNotNull($summary, 'Boundary message in NY day window must be picked up');

        // And the previous NY day must NOT find it.
        StructuredAnonymousAgent::fake([[
            'briefing' => 'should-not-be-reached',
            'proposed_actions' => [],
            'durable_facts' => [],
            'skills_emerged' => [],
            'self_improvement_score' => 0.0,
        ]]);
        $shouldBeNull = new SummarizeAgentDailyLearningAction(
            agent: $agent,
            app: $app,
            company: $company,
            cycleDate: Carbon::parse('2026-05-17'),
            dryRun: true,
        )->execute();

        $this->assertNull($shouldBeNull, 'NY May 17 must not contain the May 18 00:30 EDT message');

        // Belt-and-suspenders: restore the in-memory timezone in case the
        // test runner keeps the company instance live for the next test.
        $company->timezone = $originalTimezone;
    }

    public function testIdempotentOnReRunSameDay(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        $cycleDate = Carbon::parse('2026-05-23');

        $this->seedConversationOnCompanyTimezoneDay(
            $agent,
            $app,
            $company,
            $user->getId(),
            $cycleDate,
        );

        // First-run summary
        StructuredAnonymousAgent::fake([[
            'briefing' => 'Run #1 briefing.',
            'proposed_actions' => ['Action one.'],
            'durable_facts' => [],
            'skills_emerged' => [],
            'self_improvement_score' => 0.1,
        ]]);
        new SummarizeAgentDailyLearningAction(
            agent: $agent,
            app: $app,
            company: $company,
            cycleDate: $cycleDate,
            skipPush: true,
        )->execute();

        // Second-run overwrites — new fake feeds the second call
        StructuredAnonymousAgent::fake([[
            'briefing' => 'Run #2 briefing supersedes the first.',
            'proposed_actions' => ['Action two.'],
            'durable_facts' => [],
            'skills_emerged' => [],
            'self_improvement_score' => 0.2,
        ]]);
        new SummarizeAgentDailyLearningAction(
            agent: $agent,
            app: $app,
            company: $company,
            cycleDate: $cycleDate,
            skipPush: true,
        )->execute();

        $rows = AgentDailyCycle::query()
            ->where('agent_id', $agent->getId())
            ->where('cycle_date', '2026-05-23')
            ->get();

        $this->assertCount(1, $rows, 'updateOrCreate must produce exactly one row per (agent, date)');
        $this->assertSame('Run #2 briefing supersedes the first.', $rows->first()->morning_briefing);
    }

    /**
     * Seed one conversation with two messages timestamped inside the company
     * timezone day so the action's whereBetween catches them.
     */
    private function seedConversationOnCompanyTimezoneDay(
        Agent $agent,
        Apps $app,
        $company,
        int $userId,
        Carbon $cycleDate,
    ): void {
        $companyTz = ((string) $company->timezone) !== ''
            ? (string) $company->timezone
            : ((is_string($app->get('timezone')) && $app->get('timezone') !== '') ? $app->get('timezone') : 'UTC');
        // Anchor the moment IN the tz (parse the label there), don't shift
        // into it — same fix shape as the production action.
        $messageAt = Carbon::parse($cycleDate->toDateString(), $companyTz)->setTime(12, 0, 0)->utc();

        $conversation = AgentConversation::query()->create([
            'id' => 'conv-' . uniqid('', true),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
            'title' => 'Daily sync',
            'meta' => ['source' => 'slack'],
            'created_at' => $messageAt,
            'updated_at' => $messageAt,
        ]);

        AgentConversationMessage::query()->create([
            'id' => 'msg-' . uniqid('', true) . '-u',
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
            'agent' => 'TestAgent',
            'role' => 'user',
            'content' => 'How did the gate meeting go?',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
            'created_at' => $messageAt,
            'updated_at' => $messageAt,
        ]);

        AgentConversationMessage::query()->create([
            'id' => 'msg-' . uniqid('', true) . '-a',
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'agent' => 'TestAgent',
            'role' => 'assistant',
            'content' => 'It went well — Kevin signed off on the milestone.',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
            'created_at' => $messageAt->copy()->addSecond(),
            'updated_at' => $messageAt->copy()->addSecond(),
        ]);
    }
}
