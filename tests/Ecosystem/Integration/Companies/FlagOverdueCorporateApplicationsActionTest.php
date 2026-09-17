<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Actions\FlagOverdueCorporateApplicationsAction;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationFieldEnum as Field;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationSettingEnum as Setting;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationStatusEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Notifications\Templates\Blank;
use Tests\TestCase;

final class FlagOverdueCorporateApplicationsActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    private Apps $kanvasApp;
    private LeadReceiver $receiver;
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->del(Setting::SLA_HOURS->value);
        $this->receiver = $this->receiver('ops@example.com, lead@example.com');
        $this->now = Carbon::parse('2026-09-17 12:00:00');

        // Applications left open by other suites on a shared database would be flagged here
        // too; stamp them first (rolled back with the transaction) so each test sees only its own.
        Notification::fake();
        new FlagOverdueCorporateApplicationsAction($this->kanvasApp, $this->now)->execute();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(Setting::SLA_HOURS->value);

        parent::tearDown();
    }

    public function testFlagsAndEscalatesOnlyApplicationsPastTheSla(): void
    {
        $overdue = $this->application(CorporateApplicationStatusEnum::PENDING, hoursAgo: 30);
        $stillReviewing = $this->application(CorporateApplicationStatusEnum::NEEDS_REVIEW, hoursAgo: 25);
        $fresh = $this->application(CorporateApplicationStatusEnum::PENDING, hoursAgo: 2);
        $decided = $this->application(CorporateApplicationStatusEnum::APPROVED, hoursAgo: 90);

        $flagged = new FlagOverdueCorporateApplicationsAction($this->kanvasApp, $this->now)->execute();

        $this->assertEqualsCanonicalizing([$overdue->getId(), $stillReviewing->getId()], $flagged);
        $this->assertSame($this->now->toIso8601String(), Field::OVERDUE_AT->readFrom($overdue->fresh()));
        $this->assertNull(Field::OVERDUE_AT->readFrom($fresh->fresh()));
        $this->assertNull(Field::OVERDUE_AT->readFrom($decided->fresh()));

        Notification::assertSentOnDemandTimes(Blank::class, 2);
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === ['ops@example.com', 'lead@example.com']
                && $notification->getTemplateName() === FlagOverdueCorporateApplicationsAction::DEFAULT_TEMPLATE
        );
    }

    public function testAFlaggedApplicationIsNotEscalatedTwice(): void
    {
        $this->application(CorporateApplicationStatusEnum::PENDING, hoursAgo: 30);

        $first = new FlagOverdueCorporateApplicationsAction($this->kanvasApp, $this->now)->execute();
        $second = new FlagOverdueCorporateApplicationsAction($this->kanvasApp, $this->now->copy()->addHours(5))->execute();

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        Notification::assertSentOnDemandTimes(Blank::class, 1);
    }

    public function testSlaIsConfigurablePerApp(): void
    {
        $this->kanvasApp->set(Setting::SLA_HOURS->value, 48);
        $this->application(CorporateApplicationStatusEnum::PENDING, hoursAgo: 30);

        $this->assertSame([], new FlagOverdueCorporateApplicationsAction($this->kanvasApp, $this->now)->execute());
        Notification::assertNothingSent();
    }

    public function testFallsBackToTheRotationThenTheReceiverOwnerForRecipients(): void
    {
        $bare = $this->receiver(null);
        $this->application(CorporateApplicationStatusEnum::PENDING, hoursAgo: 30, receiver: $bare);

        new FlagOverdueCorporateApplicationsAction($this->kanvasApp, $this->now)->execute();

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === [Auth::user()->email]
        );
    }

    public function testTheScheduledCommandRunsTheActionPerApp(): void
    {
        $this->application(CorporateApplicationStatusEnum::PENDING, hoursAgo: 30);

        $this->artisan('kanvas-company:flag-overdue-applications', ['app_id' => $this->kanvasApp->getId()])
            ->expectsOutputToContain('1 overdue')
            ->assertSuccessful();
    }

    private function application(CorporateApplicationStatusEnum $status, int $hoursAgo, ?LeadReceiver $receiver = null): Lead
    {
        $lead = Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), Auth::user()->getCurrentCompany()->getId())
            ->withReceiverId(($receiver ?? $this->receiver)->getId())
            ->create(['title' => 'Solicitud ' . fake()->company()]);

        $lead->created_at = $this->now->copy()->subHours($hoursAgo);
        $lead->saveQuietly();

        Field::STATUS->writeTo($lead, $status->value);

        return $lead->fresh();
    }

    private function receiver(?string $notificationEmail): LeadReceiver
    {
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        return LeadReceiver::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'users_id' => $user->getId(),
            'agents_id' => 0,
            'rotations_id' => 0,
            'leads_sources_id' => 0,
            'lead_types_id' => 0,
            'name' => 'SLA ' . fake()->word(),
            'source_name' => 'sla',
            'notification_email' => $notificationEmail,
            'is_default' => 0,
        ]);
    }
}
