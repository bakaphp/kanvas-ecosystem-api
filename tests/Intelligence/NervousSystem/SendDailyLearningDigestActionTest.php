<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Bouncer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;
use Kanvas\NervousSystem\DailyLearning\Actions\EnsureAgentReportRoleAction;
use Kanvas\NervousSystem\DailyLearning\Actions\SendDailyLearningDigestAction;
use Kanvas\NervousSystem\DailyLearning\Notifications\DailyLearningDigestNotification;
use Tests\TestCase;

class SendDailyLearningDigestActionTest extends TestCase
{
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

        // No user has the AgentReport role → enumerate returns empty
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

        // Bootstrap the role then assign it to the test user
        new EnsureAgentReportRoleAction($app)->execute();
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
