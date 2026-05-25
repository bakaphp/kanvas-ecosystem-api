<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Bouncer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;
use Kanvas\NervousSystem\DailyLearning\Actions\EnsureAgentReportRoleAction;
use Kanvas\NervousSystem\DailyLearning\Actions\SendDailyLearningDigestAction;
use Kanvas\NervousSystem\DailyLearning\Notifications\DailyLearningDigestNotification;
use Tests\TestCase;

class SendDailyLearningDigestActionTest extends TestCase
{
    /**
     * Bootstrap the AgentReport role on the current app once per test.
     * `EnsureAgentReportRoleAction` is idempotent (firstOrCreate), so
     * re-running across the suite is safe. The single test that needs
     * the role *absent* (testReturnsZeroAndDoesNotCrashWhenAgentReportRoleNotBootstrapped)
     * deletes it after setUp.
     */
    public function setUp(): void
    {
        parent::setUp();

        new EnsureAgentReportRoleAction(app(Apps::class))->execute();
    }

    public function testReturnsZeroAndSkipsLedgerWhenNoCyclesForDate(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        Notification::fake();

        $sent = new SendDailyLearningDigestAction(
            app: $app,
            company: $company,
            cycleDate: Carbon::parse('2026-05-23'),
        )->execute();

        $this->assertSame(0, $sent);
        Notification::assertNothingSent();
        $this->assertDatabaseMissing(
            'nervous_system_events',
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'event_type' => 'agent.daily_learning.digest_sent',
            ],
            'intelligence',
        );
    }

    public function testReturnsZeroWhenNoRecipientsAssignedToAgentReportRole(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->seedCycle($app, $company, '2026-05-23');

        // Role exists from setUp(); no user is assigned to it — exercises
        // the "role exists, no recipients" branch.
        Notification::fake();

        $sent = new SendDailyLearningDigestAction(
            app: $app,
            company: $company,
            cycleDate: Carbon::parse('2026-05-23'),
        )->execute();

        $this->assertSame(0, $sent);
        Notification::assertNothingSent();
    }

    public function testReturnsZeroAndDoesNotCrashWhenAgentReportRoleNotBootstrapped(): void
    {
        // Regression: getCompanyAppUserByRole calls firstOrFail on the role
        // lookup, which throws ModelNotFoundException if the AgentReport
        // role was never bootstrapped for this app. The action must catch
        // that and treat it like "no recipients" so the daily cron doesn't
        // crash on tenants who haven't run ensure-agent-report-role yet.
        //
        // setUp() bootstraps the role for the rest of the suite — undo that
        // here to test the missing-role path explicitly.
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->seedCycle($app, $company, '2026-05-23');

        Role::query()
            ->where('name', RolesEnums::AGENT_REPORT->value)
            ->where('scope', RolesEnums::getScope($app))
            ->delete();

        Notification::fake();

        $sent = new SendDailyLearningDigestAction(
            app: $app,
            company: $company,
            cycleDate: Carbon::parse('2026-05-23'),
        )->execute();

        $this->assertSame(0, $sent);
        Notification::assertNothingSent();
    }

    public function testDispatchesOneNotificationPerRecipient(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->seedCycle($app, $company, '2026-05-23');

        // Role bootstrapped by setUp(); assign it to the test user.
        Bouncer::scope()->to(RolesEnums::getScope($app));
        Bouncer::assign(RolesEnums::AGENT_REPORT->value)->to($user);

        Notification::fake();

        $sent = new SendDailyLearningDigestAction(
            app: $app,
            company: $company,
            cycleDate: Carbon::parse('2026-05-23'),
        )->execute();

        $this->assertSame(1, $sent);
        Notification::assertSentTo($user, DailyLearningDigestNotification::class);
        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'event_type' => 'agent.daily_learning.digest_sent',
            ],
            'intelligence',
        );
    }

    private function seedCycle(Apps $app, $company, string $cycleDate): AgentDailyCycle
    {
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'felix-sales']);

        return AgentDailyCycle::query()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
            'cycle_date' => $cycleDate,
            'morning_briefing' => 'Yesterday went well — closed two deals.',
            'proposed_actions' => ['Follow up with Steven', 'Review the EVT schedule'],
            'durable_facts' => ['Steven handles PNP communications.'],
            'skills_emerged' => [['name' => 'deal-closing', 'confidence' => 0.7]],
            'self_improvement_score' => '0.25',
            'signed_by_text' => '— felix-sales, signing in',
        ]);
    }
}
