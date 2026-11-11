<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\FollowUp\Actions\SendLeadFollowUpDailySummaryAction;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpEmailTemplateEnum;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class SendLeadFollowUpDailySummaryActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence'];

    private Apps $testApp;

    private Companies $company;

    /** @var list<Users> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->testApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->ensureFollowUpDevRole();
    }

    protected function tearDown(): void
    {
        Bouncer::scope()->to(RolesEnums::getScope($this->testApp));
        foreach ($this->members as $member) {
            Bouncer::retract(RolesEnums::FOLLOW_UP_DEV->value)->from($member);
        }

        parent::tearDown();
    }

    public function testEmailsTheCompanyFollowUpDevsTheSummary(): void
    {
        $dev = $this->makeMember(RolesEnums::FOLLOW_UP_DEV);

        $sentTo = new SendLeadFollowUpDailySummaryAction(
            $this->testApp,
            $this->company,
            $this->summary([
                'sent' => ['total' => 2, 'by_channel' => ['sms' => 2], 'by_stage' => []],
                'failed' => [
                    'total' => 1,
                    'by_reason' => ['send_failed' => 1],
                    'recent' => [['lead_id' => 1, 'lead_title' => 'Maria Follow Up', 'reason' => 'send_failed', 'error' => 'Twilio 21211', 'channel' => 'sms']],
                ],
            ]),
        )->execute();

        $this->assertContains($dev->email, $sentTo);
        Notification::assertSentTo(
            $dev,
            Blank::class,
            static fn (Blank $notification): bool => $notification->getTemplateName() === FollowUpEmailTemplateEnum::DAILY_SUMMARY->value
                && $notification->getData()['failed']['recent'][0]['lead_title'] === 'Maria Follow Up'
                && $notification->getData()['company_name'] === auth()->user()->getCurrentCompany()->name
        );
    }

    public function testInactiveMembersAndOtherRolesNeverReceiveIt(): void
    {
        $inactiveDev = $this->makeMember(RolesEnums::FOLLOW_UP_DEV);
        DB::table('users_associated_apps')
            ->where('users_id', $inactiveDev->getId())
            ->where('apps_id', $this->testApp->getId())
            ->where('companies_id', $this->company->getId())
            ->update(['is_active' => 0]);
        $developer = $this->makeMember(RolesEnums::DEVELOPER);

        new SendLeadFollowUpDailySummaryAction(
            $this->testApp,
            $this->company,
            $this->summary(['sent' => ['total' => 3, 'by_channel' => [], 'by_stage' => []]]),
        )->execute();

        Notification::assertNotSentTo($inactiveDev, Blank::class);
        Notification::assertNotSentTo($developer, Blank::class);
    }

    public function testSendsNothingForADayWithoutActivity(): void
    {
        $dev = $this->makeMember(RolesEnums::FOLLOW_UP_DEV);

        $sentTo = new SendLeadFollowUpDailySummaryAction(
            $this->testApp,
            $this->company,
            $this->summary(['skipped' => ['total' => 40, 'by_reason' => ['too_soon' => 40]]]),
        )->execute();

        $this->assertSame([], $sentTo);
        Notification::assertNotSentTo($dev, Blank::class);
    }

    private function summary(array $payload): Event
    {
        return new AppendEventAction(
            new EventData(
                app: $this->testApp,
                company: $this->company,
                sourceDomain: 'follow_up',
                eventType: 'follow_up.daily.summary',
                status: EventStatusEnum::INFO,
                sourceEntityType: null,
                sourceEntityId: null,
                payload: [...$payload, 'for_date' => '2026-10-21'],
            ),
        )->execute();
    }

    /**
     * The membership row goes through the default handle so the recipient query sees it inside
     * this test's transaction.
     */
    private function makeMember(RolesEnums $role): Users
    {
        $user = Users::factory()->create();

        DB::table('users_associated_apps')->insert([
            'users_id' => $user->getId(),
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'email' => $user->email,
            'displayname' => $user->displayname,
            'password' => '',
            'user_role' => strtolower($role->value),
            'user_active' => 1,
            'is_active' => 1,
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
        ]);

        Bouncer::scope()->to(RolesEnums::getScope($this->testApp));
        Bouncer::assign($role->value)->to($user);
        $this->members[] = $user;

        return $user;
    }

    /**
     * The role is created per app by `CreateAppsAction::acl()`; the test app predates it.
     */
    private function ensureFollowUpDevRole(): void
    {
        try {
            new CreateRoleAction(
                name: RolesEnums::FOLLOW_UP_DEV->value,
                title: 'Follow-up Dev',
                app: $this->testApp,
            )->execute();
        } catch (ValidationException) {
            // Already there from a prior run on a database that keeps roles across tests.
        }
    }
}
