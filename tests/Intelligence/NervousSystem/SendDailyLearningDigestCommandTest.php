<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Bouncer;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDailyCycle;
use Kanvas\NervousSystem\DailyLearning\Actions\EnsureAgentReportRoleAction;
use Kanvas\NervousSystem\DailyLearning\Notifications\DailyLearningDigestNotification;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

class SendDailyLearningDigestCommandTest extends TestCase
{
    /**
     * Far-future, test-unique date so the seeded cycle never collides with
     * the 2026-05-23 rows the sibling action test queries. This suite does
     * not transact (createApplication mints a fresh user per test and writes
     * persist), so the test cleans up after itself in tearDown instead.
     */
    private const string CYCLE_DATE = '2031-07-07';

    private ?int $seededAgentId = null;

    protected function tearDown(): void
    {
        $app = app(Apps::class);
        Bouncer::scope()->to(RolesEnums::getScope($app));
        Bouncer::retract(RolesEnums::AGENT_REPORT->value)->from(auth()->user());

        AgentDailyCycle::query()->where('cycle_date', self::CYCLE_DATE)->forceDelete();
        if ($this->seededAgentId !== null) {
            Agent::query()->where('id', $this->seededAgentId)->forceDelete();
        }

        Event::query()
            ->where('event_type', 'agent.daily_learning.digest_sent')
            ->where('payload->cycle_date', self::CYCLE_DATE)
            ->delete();

        parent::tearDown();
    }

    /**
     * Regression for the cross-tenant Bouncer scope leak: the command fans out
     * over (app, company) tuples, and SendDailyLearningDigestAction resolves
     * recipients via the Bouncer-scoped Role model
     * (RolesRepository::getByNameFromCompany -> Role::firstOrFail()). If the
     * command does not rebind the app scope per iteration, Bouncer's auto-applied
     * `WHERE scope = <leaked scope>` makes the role lookup miss, the action
     * swallows the ModelNotFoundException, and every tenant silently sends 0.
     *
     * Here we deliberately pin Bouncer to a foreign scope BEFORE running the
     * command — the email only goes out if the command's overwriteAppService()
     * resets the scope to the tenant's app.
     */
    public function testCommandRebindsLeakedAppScopeSoDigestStillSends(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new EnsureAgentReportRoleAction($app)->execute();
        $this->seedCycle($app, $company, self::CYCLE_DATE);

        Bouncer::scope()->to(RolesEnums::getScope($app));
        Bouncer::assign(RolesEnums::AGENT_REPORT->value)->to($user);

        // Simulate the leak: a prior iteration (or process boot) left Bouncer
        // pinned to a different app's scope. Pre-fix this killed the role lookup.
        Bouncer::scope()->to('app_999999_company_0');

        Notification::fake();

        $this->artisan('kanvas:nervous-system:send-daily-learning-digest', [
            '--date' => self::CYCLE_DATE,
            '--app' => (string) $app->getId(),
            '--company' => (string) $company->getId(),
        ])->assertExitCode(0);

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

        $this->seededAgentId = $agent->getId();

        return AgentDailyCycle::query()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'agent_id' => $agent->getId(),
            'cycle_date' => $cycleDate,
            'morning_briefing' => 'Yesterday went well — closed two deals.',
            'proposed_actions' => ['Follow up with Steven'],
            'durable_facts' => ['Steven handles PNP communications.'],
            'skills_emerged' => [['name' => 'deal-closing', 'confidence' => 0.7]],
            'self_improvement_score' => '0.25',
            'signed_by_text' => '— felix-sales, signing in',
        ]);
    }
}
