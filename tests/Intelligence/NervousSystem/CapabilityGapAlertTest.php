<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Capability\Notifications\CapabilityGapReportedNotification;
use Kanvas\NervousSystem\Capability\Services\CapabilityGapAlertService;
use Kanvas\NervousSystem\Plan\Actions\RecordCapabilityGapAction;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

class CapabilityGapAlertTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence', 'crm'];

    private const string PLATFORM_LIST = 'roadmap@example-corp.com, pm@example-corp.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kanvas.capability_gap.alert_emails' => null]);
    }

    public function testANewGapEmailsThePlatformList(): void
    {
        Notification::fake();
        config(['kanvas.capability_gap.alert_emails' => self::PLATFORM_LIST]);

        $plan = new RecordCapabilityGapAction(
            agent: $this->makeAgent(),
            topic: 'zzqq frobnicate the widgets',
            context: 'The salesperson wanted the widgets frobnicated before the demo.',
            requestedBy: static::$cachedUser,
        )->execute();

        Notification::assertSentOnDemand(
            CapabilityGapReportedNotification::class,
            function (CapabilityGapReportedNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($plan): bool {
                $data = $notification->getData();

                return $notifiable->routes['mail'] === ['roadmap@example-corp.com', 'pm@example-corp.com']
                    && $data['plan_id'] === $plan->getId()
                    && $data['request_count'] === 1
                    && $data['requested_by_email'] === static::$cachedUser->email;
            }
        );
    }

    public function testNothingIsSentWhenNobodyIsConfiguredToHearIt(): void
    {
        Notification::fake();

        new RecordCapabilityGapAction($this->makeAgent(), 'zzqq frobnicate the widgets')->execute();

        // Not assertNothingSent(): provisioning the agent's user sends its own verification email.
        Notification::assertSentOnDemandTimes(CapabilityGapReportedNotification::class, 0);
    }

    /** The interesting signal is "asked again", so a repeat mails again and says how many times. */
    public function testARepeatRequestEmailsAgainWithTheCount(): void
    {
        Notification::fake();
        config(['kanvas.capability_gap.alert_emails' => self::PLATFORM_LIST]);
        $agent = $this->makeAgent();

        new RecordCapabilityGapAction($agent, 'zzqq frobnicate the widgets', 'first ask')->execute();
        new RecordCapabilityGapAction($agent, 'zzqq frobnicate the widgets', 'second ask')->execute();

        Notification::assertSentOnDemandTimes(CapabilityGapReportedNotification::class, 2);
        Notification::assertSentOnDemand(
            CapabilityGapReportedNotification::class,
            fn (CapabilityGapReportedNotification $notification): bool => $notification->getData()['request_count'] === 2
                && $notification->getData()['context'] === 'second ask'
        );
    }

    /**
     * Writes an app setting, which lands in Redis shared by every parallel process — serial, and
     * removed again before the test ends.
     */
    #[Group('serial')]
    public function testAnAppSettingReplacesThePlatformList(): void
    {
        $app = app(Apps::class);
        $key = AppSettingsEnums::CAPABILITY_GAP_ALERT_EMAILS->getValue();
        config(['kanvas.capability_gap.alert_emails' => self::PLATFORM_LIST]);

        try {
            $app->set($key, 'tenant-roadmap@example-corp.com');

            $this->assertSame(['tenant-roadmap@example-corp.com'], new CapabilityGapAlertService($app)->recipients());
        } finally {
            $app->del($key);
        }

        $this->assertSame(
            ['roadmap@example-corp.com', 'pm@example-corp.com'],
            new CapabilityGapAlertService($app)->recipients()
        );
    }

    public function testTheEmailCarriesWhatAReviewerNeeds(): void
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();
        $agent = $this->makeAgent();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create(['title' => 'Zzqq Widgets Co — fleet of 40']);

        $session = new Session();
        $session->entity_namespace = Lead::class;
        $session->entity_id = $lead->getId();
        $session->uuid = 'email-zzqq-' . uniqid();

        $plan = new RecordCapabilityGapAction(
            agent: $agent,
            topic: 'zzqq frobnicate the widgets',
            context: 'Wanted every widget frobnicated before the Friday demo.',
            consideredTools: ['zzqq_schedule_task'],
            whyNotFit: 'zzqq_schedule_task only schedules, it cannot frobnicate.',
            requestedBy: static::$cachedUser,
            session: $session,
            lead: $lead,
        )->execute();

        $details = new CapabilityGapAlertService($app)->details($plan);

        $this->assertSame($lead->getId(), $details['lead_id']);
        $this->assertSame('email', $details['channel']);
        $this->assertSame($agent->name, $details['agent_name']);

        $html = new CapabilityGapReportedNotification($plan, $details)->getEmailContent();

        foreach ([
            'zzqq frobnicate the widgets',
            'Wanted every widget frobnicated before the Friday demo.',
            'zzqq_schedule_task',
            'it cannot frobnicate',
            $agent->name,
            static::$cachedUser->email,
            'Zzqq Widgets Co',
            $app->name,
            $company->name,
            '#' . $plan->getId(),
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }
}
