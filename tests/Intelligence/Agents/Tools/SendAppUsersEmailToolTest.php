<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\StateEnums;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendAppUsersEmailTool;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingAudienceEnum;
use Kanvas\Notifications\Mailings\Enums\AppUserMailingStatusEnum;
use Kanvas\Notifications\Mailings\Jobs\SendAppUsersEmailChunkJob;
use Kanvas\Notifications\Mailings\Jobs\SendAppUsersEmailJob;
use Kanvas\Notifications\Mailings\Models\AppUserMailing;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Templates\Models\Templates;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;
use Tests\Traits\MakesActiveUsers;

class SendAppUsersEmailToolTest extends TestCase
{
    use DatabaseTransactions;
    use MakesActiveUsers;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'commerce', 'crm'];

    private function agentFor(Companies $company): Agent
    {
        return Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => Users::factory()->create()->getId()]);
    }

    private function tool(Companies $company, ?Users $requester = null, ?Agent $agent = null): SendAppUsersEmailTool
    {
        return new SendAppUsersEmailTool()
            ->withContext(app(Apps::class), $company, auth()->user(), $agent ?? $this->agentFor($company))
            ->forRequestingUser($requester ?? auth()->user());
    }

    private function scheduledMailing(Companies $company, Carbon $scheduledAt): AppUserMailing
    {
        $type = $this->makeOrderType($company);
        $this->makeUserWhoPaid($type);
        $template = $this->template($company);
        $result = $this->tool($company)->__invoke(
            mode: 'send',
            template_name: $template->name,
            order_types: $type->name,
            timezone: 'UTC',
            confirm_total: 1,
            send_at: $scheduledAt->format('Y-m-d\TH:i:s'),
        );

        return AppUserMailing::query()->findOrFail($result['mailing_id']);
    }

    private function template(Companies $company): Templates
    {
        return Templates::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'mailing-' . uniqid(),
            'template' => '<p>Hi</p>',
            'parent_template_id' => 0,
            'is_system' => false,
        ]);
    }

    private function mailingCount(Companies $company): int
    {
        return AppUserMailing::query()->where('companies_id', $company->getId())->count();
    }

    public function testPreviewSendsNothingAndReportsTheAudience(): void
    {
        Bus::fake();
        Notification::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $this->makeUserWhoPaid($type);
        $this->makeUserWhoPaid($type);
        $this->makeUserWhoPaid($type, ['banned' => 1]);
        $template = $this->template($company);

        $result = $this->tool($company)->__invoke(
            mode: 'preview',
            template_name: $template->name,
            order_types: $type->name,
            timezone: 'UTC',
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['total_active_users']);
        $this->assertSame(2, $result['eligible_total']);
        $this->assertSame(1, $result['total_excluded']);
        $this->assertSame(1, $result['excluded']['banned']);
        $this->assertCount(2, $result['sample_emails']);
        $this->assertStringContainsString('***@example.test', $result['sample_emails'][0]);
        $this->assertStringContainsString('confirm_total = 2', $result['note']);
        $this->assertSame(0, $this->mailingCount($company));
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
        Notification::assertNothingSent();
    }

    public function testPreviewHonorsTheSinceAndUntilWindow(): void
    {
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $this->makeUserWhoPaid($type);
        $template = $this->template($company);

        $inside = $this->tool($company)->__invoke(
            mode: 'preview',
            template_name: $template->name,
            order_types: $type->name,
            since: '2026-02-10',
            until: '2026-02-10',
            timezone: 'UTC',
        );
        $outside = $this->tool($company)->__invoke(
            mode: 'preview',
            template_name: $template->name,
            order_types: $type->name,
            since: '2026-03-01',
            timezone: 'UTC',
        );

        $this->assertSame(1, $inside['eligible_total']);
        $this->assertSame(0, $outside['eligible_total']);
    }

    public function testAnInvalidTimezoneIsAFixableArgumentNotACrash(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $template = $this->template($company);

        $preview = $this->tool($company)->__invoke(
            mode: 'preview',
            template_name: $template->name,
            timezone: 'Mars/Olympus',
        );
        $send = $this->tool($company)->__invoke(
            mode: 'send',
            template_name: $template->name,
            timezone: 'Mars/Olympus',
            confirm_total: 1,
        );

        foreach ([$preview, $send] as $result) {
            $this->assertFalse($result['success']);
            $this->assertSame('invalid_args', $result['outcome']);
            $this->assertStringContainsString('Mars/Olympus', $result['message'] ?? $result['error'] ?? '');
        }
        $this->assertSame(0, $this->mailingCount($company));
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
    }

    public function testSendRefusesWithoutAMatchingConfirmTotal(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $this->makeUserWhoPaid($type);
        $this->makeUserWhoPaid($type);
        $template = $this->template($company);

        $missing = $this->tool($company)->__invoke(
            mode: 'send',
            template_name: $template->name,
            order_types: $type->name,
        );
        $wrong = $this->tool($company)->__invoke(
            mode: 'send',
            template_name: $template->name,
            order_types: $type->name,
            confirm_total: 5,
        );

        foreach ([$missing, $wrong] as $result) {
            $this->assertFalse($result['success']);
            $this->assertSame('invalid_args', $result['outcome']);
            $this->assertSame(2, $result['eligible_total']);
        }
        $this->assertSame(0, $this->mailingCount($company));
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
    }

    public function testSendFreezesTheAudienceAndDispatchesTheJob(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $first = $this->makeUserWhoPaid($type);
        $second = $this->makeUserWhoPaid($type);
        $this->makeUserWhoPaid($type, ['user_active' => 0]);
        $template = $this->template($company);

        $result = $this->tool($company)->__invoke(
            mode: 'send',
            template_name: $template->name,
            subject: 'We miss you',
            order_types: $type->name,
            timezone: 'UTC',
            confirm_total: 2,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['eligible_total']);
        $this->assertSame(1, $result['excluded']['inactive']);

        $mailing = AppUserMailing::query()->where('uuid', $result['mailing_uuid'])->firstOrFail();
        $this->assertSame($company->getId(), $mailing->companies_id);
        $this->assertSame(auth()->user()->getId(), $mailing->users_id);
        $this->assertSame(AppUserMailingAudienceEnum::ACTIVE_USERS, $mailing->audience);
        $this->assertSame($template->name, $mailing->template_name);
        $this->assertSame('We miss you', $mailing->subject);
        $this->assertSame(AppUserMailingStatusEnum::QUEUED, $mailing->status);
        $this->assertSame(2, $mailing->confirmed_total);
        $this->assertSame(2, $mailing->total_eligible);
        $this->assertSame(1, $mailing->total_excluded);
        $this->assertSame([$type->name], $mailing->criteria['order_types']);
        $this->assertEqualsCanonicalizing([$first->getId(), $second->getId()], $mailing->recipient_ids);
        Bus::assertDispatched(
            SendAppUsersEmailJob::class,
            fn (SendAppUsersEmailJob $job): bool => $job->mailing->is($mailing) && $job->queue === 'app-mailing'
        );
    }

    public function testAUserWhoPaysAfterTheApprovalIsNotMailed(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $first = $this->makeUserWhoPaid($type);
        $second = $this->makeUserWhoPaid($type);
        $template = $this->template($company);
        $result = $this->tool($company)->__invoke(
            mode: 'send',
            template_name: $template->name,
            order_types: $type->name,
            timezone: 'UTC',
            confirm_total: 2,
        );
        $this->makeUserWhoPaid($type);
        $mailing = AppUserMailing::query()->where('uuid', $result['mailing_uuid'])->firstOrFail();

        new SendAppUsersEmailJob(app(Apps::class), $mailing)->handle();

        Bus::assertDispatched(
            SendAppUsersEmailChunkJob::class,
            fn (SendAppUsersEmailChunkJob $chunk): bool => $chunk->userIds === $mailing->recipient_ids
                && count($chunk->userIds) === 2
        );
        $this->assertEqualsCanonicalizing([$first->getId(), $second->getId()], $mailing->recipient_ids);
        $this->assertSame(2, $mailing->refresh()->total_eligible);
    }

    public function testTestModeMailsOnlyAUserOfTheCompanyAndRecordsNothing(): void
    {
        Bus::fake();
        Notification::fake();
        $company = Companies::factory()->create();
        $teammate = Users::factory()->create();
        $company->associateUser($teammate, StateEnums::YES->getValue(), $company->branch);
        $template = $this->template($company);

        $result = $this->tool($company)->__invoke(
            mode: 'test',
            template_name: $template->name,
            test_to: strtoupper((string) $teammate->email),
        );

        $this->assertTrue($result['success']);
        $this->assertSame($teammate->email, $result['test_sent_to']);
        Notification::assertSentTo($teammate, Blank::class);
        $this->assertSame(0, $this->mailingCount($company));
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
    }

    public function testTestModeToAnOutsiderIsDenied(): void
    {
        Notification::fake();
        $company = Companies::factory()->create();
        $outsider = Users::factory()->create();
        $template = $this->template($company);

        $result = $this->tool($company)->__invoke(
            mode: 'test',
            template_name: $template->name,
            test_to: $outsider->email,
        );

        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
        Notification::assertNothingSent();
    }

    public function testANonAdminRequesterIsDeniedInEveryMode(): void
    {
        Bus::fake();
        Notification::fake();
        $company = Companies::factory()->create();
        $template = $this->template($company);
        $member = Users::factory()->create();

        $result = $this->tool($company, $member)->__invoke(mode: 'preview', template_name: $template->name);

        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
        $this->assertSame(0, $this->mailingCount($company));
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
    }

    public function testWithoutAKnownRequestingUserNothingRunsEvenWhenTheAgentIsAnAdmin(): void
    {
        Bus::fake();
        Notification::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $this->makeUserWhoPaid($type);
        $template = $this->template($company);
        $tool = new SendAppUsersEmailTool()->withContext(app(Apps::class), $company, auth()->user());

        $send = $tool->__invoke(
            mode: 'send',
            template_name: $template->name,
            order_types: $type->name,
            timezone: 'UTC',
            confirm_total: 1,
        );
        $preview = $tool->__invoke(mode: 'preview', template_name: $template->name);

        foreach ([$send, $preview] as $result) {
            $this->assertFalse($result['success']);
            $this->assertSame('denied', $result['outcome']);
        }
        $this->assertSame(0, $this->mailingCount($company));
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
        Notification::assertNothingSent();
    }

    public function testAnUnknownTemplateIsReportedAndNothingRuns(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();

        $result = $this->tool($company)->__invoke(mode: 'send', template_name: 'does-not-exist-' . uniqid(), confirm_total: 1);

        $this->assertFalse($result['success'] ?? false);
        $this->assertSame('not_found', $result['outcome']);
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
    }

    public function testAnUnknownModeIsRejected(): void
    {
        $company = Companies::factory()->create();
        $template = $this->template($company);

        $result = $this->tool($company)->__invoke(mode: 'blast', template_name: $template->name);

        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function testAModeThatSendsNeedsATemplate(): void
    {
        $company = Companies::factory()->create();

        $result = $this->tool($company)->__invoke(mode: 'preview');

        $this->assertSame('invalid_args', $result['outcome']);
    }

    public function testStatusReportsTheProgressOfAMailingOfThisCompany(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $this->makeUserWhoPaid($type);
        $template = $this->template($company);
        $sent = $this->tool($company)->__invoke(
            mode: 'send',
            template_name: $template->name,
            order_types: $type->name,
            timezone: 'UTC',
            confirm_total: 1,
        );
        $mailing = AppUserMailing::query()->findOrFail($sent['mailing_id']);
        $mailing->recordChunkResult(1, 0);

        $result = $this->tool($company)->__invoke(mode: 'status', mailing_id: $mailing->getId());

        $this->assertTrue($result['success']);
        $this->assertSame($mailing->getId(), $result['mailing_id']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame(1, $result['confirmed_total']);
        $this->assertSame(1, $result['eligible_total']);
        $this->assertSame(1, $result['sent_count']);
        $this->assertSame(0, $result['failed_count']);
        $this->assertNull($result['failed_reason']);
    }

    public function testStatusDoesNotLeakAMailingOfAnotherCompany(): void
    {
        Bus::fake();
        $owner = Companies::factory()->create();
        $other = Companies::factory()->create();
        $type = $this->makeOrderType($owner);
        $this->makeUserWhoPaid($type);
        $template = $this->template($owner);
        $sent = $this->tool($owner)->__invoke(
            mode: 'send',
            template_name: $template->name,
            order_types: $type->name,
            timezone: 'UTC',
            confirm_total: 1,
        );

        $result = $this->tool($other)->__invoke(mode: 'status', mailing_id: $sent['mailing_id']);
        $missing = $this->tool($owner)->__invoke(mode: 'status');

        $this->assertFalse($result['success'] ?? false);
        $this->assertSame('not_found', $result['outcome']);
        $this->assertSame('invalid_args', $missing['outcome']);
    }

    public function testSendAtSchedulesTheMailingWithFrozenRecipientsAndDispatchesNothing(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $user = $this->makeUserWhoPaid($type);
        $template = $this->template($company);
        $when = Carbon::now('UTC')->addDays(3)->startOfMinute();

        $result = $this->tool($company)->__invoke(
            mode: 'send',
            template_name: $template->name,
            order_types: $type->name,
            timezone: 'UTC',
            confirm_total: 1,
            send_at: $when->format('Y-m-d\TH:i:s'),
        );

        $this->assertTrue($result['success']);
        $this->assertSame('scheduled', $result['status']);
        $mailing = AppUserMailing::query()->findOrFail($result['mailing_id']);
        $this->assertSame(AppUserMailingStatusEnum::SCHEDULED, $mailing->status);
        $this->assertTrue($mailing->scheduled_at->equalTo($when));
        $this->assertSame(1, $mailing->confirmed_total);
        $this->assertSame([$user->getId()], $mailing->recipient_ids);
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
    }

    public function testSendAtIsReadInTheGivenTimezoneAndStoredInUtc(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $this->makeUserWhoPaid($type);
        $template = $this->template($company);
        $localDay = Carbon::now('America/Santo_Domingo')->addDays(2)->format('Y-m-d');

        $result = $this->tool($company)->__invoke(
            mode: 'send',
            template_name: $template->name,
            order_types: $type->name,
            timezone: 'America/Santo_Domingo',
            confirm_total: 1,
            send_at: $localDay . 'T09:00:00',
        );

        $mailing = AppUserMailing::query()->findOrFail($result['mailing_id']);
        $this->assertSame($localDay . ' 13:00:00', $mailing->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function testSendAtMustBeInTheFutureWithinSixtyDaysAndWellFormed(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $this->makeUserWhoPaid($type);
        $template = $this->template($company);

        $attempts = [
            Carbon::now('UTC')->subHour()->format('Y-m-d\TH:i:s'),
            Carbon::now('UTC')->addDays(61)->format('Y-m-d\TH:i:s'),
            'tomorrow morning',
            '12/01/2026 9am',
        ];

        foreach ($attempts as $sendAt) {
            $result = $this->tool($company)->__invoke(
                mode: 'send',
                template_name: $template->name,
                order_types: $type->name,
                timezone: 'UTC',
                confirm_total: 1,
                send_at: $sendAt,
            );

            $this->assertFalse($result['success'], $sendAt);
            $this->assertSame('invalid_args', $result['outcome'], $sendAt);
        }
        $this->assertSame(0, $this->mailingCount($company));
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
    }

    public function testCancelStopsAScheduledMailing(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $mailing = $this->scheduledMailing($company, Carbon::now('UTC')->addDay());

        $result = $this->tool($company)->__invoke(mode: 'cancel', mailing_id: $mailing->getId());

        $this->assertTrue($result['success']);
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame(AppUserMailingStatusEnum::CANCELLED, $mailing->refresh()->status);
    }

    public function testCancelRefusesAMailingThatAlreadyStartedOrFinished(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $statuses = [
            AppUserMailingStatusEnum::QUEUED,
            AppUserMailingStatusEnum::SENDING,
            AppUserMailingStatusEnum::COMPLETED,
        ];

        foreach ($statuses as $status) {
            $mailing = $this->scheduledMailing($company, Carbon::now('UTC')->addDay());
            $mailing->status = $status;
            $mailing->saveOrFail();

            $result = $this->tool($company)->__invoke(mode: 'cancel', mailing_id: $mailing->getId());

            $this->assertFalse($result['success'], $status->value);
            $this->assertSame('invalid_args', $result['outcome']);
            $this->assertSame($status, $mailing->refresh()->status);
        }
    }

    public function testCancelDoesNotReachAMailingOfAnotherCompany(): void
    {
        Bus::fake();
        $owner = Companies::factory()->create();
        $other = Companies::factory()->create();
        $mailing = $this->scheduledMailing($owner, Carbon::now('UTC')->addDay());

        $result = $this->tool($other)->__invoke(mode: 'cancel', mailing_id: $mailing->getId());

        $this->assertFalse($result['success'] ?? false);
        $this->assertSame('not_found', $result['outcome']);
        $this->assertSame(AppUserMailingStatusEnum::SCHEDULED, $mailing->refresh()->status);
    }

    public function testStatusExposesTheScheduledTime(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $when = Carbon::now('UTC')->addDays(2)->startOfMinute();
        $mailing = $this->scheduledMailing($company, $when);

        $result = $this->tool($company)->__invoke(mode: 'status', mailing_id: $mailing->getId());

        $this->assertSame('scheduled', $result['status']);
        $this->assertTrue(Carbon::parse($result['scheduled_at'])->equalTo($when));
    }

    public function testAnUnattendedRequesterCannotSendTestOrCancelButCanPreviewAndStatus(): void
    {
        Bus::fake();
        Notification::fake();
        $company = Companies::factory()->create();
        $type = $this->makeOrderType($company);
        $this->makeUserWhoPaid($type);
        $template = $this->template($company);
        $scheduled = $this->scheduledMailing($company, Carbon::now('UTC')->addDay());
        $before = $this->mailingCount($company);
        $agent = $this->agentFor($company);
        $aiUser = Users::factory()->create();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $aiUser->getId());
        $unattended = [
            'agent user' => $agent->user,
            'company ai user' => $aiUser,
        ];

        foreach ($unattended as $label => $requester) {
            Bouncer::scope()->to(RolesEnums::getScope(app(Apps::class)));
            Bouncer::assign(RolesEnums::ADMIN->value)->to($requester);
            Bouncer::refreshFor($requester);
            $this->assertTrue($requester->isAdmin(), $label);
            $tool = $this->tool($company, $requester, $agent);

            $send = $tool->__invoke(
                mode: 'send',
                template_name: $template->name,
                order_types: $type->name,
                timezone: 'UTC',
                confirm_total: 1,
            );
            $scheduledSend = $tool->__invoke(
                mode: 'send',
                template_name: $template->name,
                order_types: $type->name,
                timezone: 'UTC',
                confirm_total: 1,
                send_at: Carbon::now('UTC')->addDay()->format('Y-m-d\TH:i:s'),
            );
            $test = $tool->__invoke(mode: 'test', template_name: $template->name, test_to: auth()->user()->email);
            $cancel = $tool->__invoke(mode: 'cancel', mailing_id: $scheduled->getId());

            foreach ([$send, $scheduledSend, $test, $cancel] as $result) {
                $this->assertFalse($result['success'], $label);
                $this->assertSame('denied', $result['outcome'], $label);
                $this->assertStringContainsString('Do not retry', $result['note'], $label);
                $this->assertStringContainsString('person has to approve', $result['note'], $label);
            }

            $preview = $tool->__invoke(
                mode: 'preview',
                template_name: $template->name,
                order_types: $type->name,
                timezone: 'UTC',
            );
            $status = $tool->__invoke(mode: 'status', mailing_id: $scheduled->getId());
            $this->assertTrue($preview['success'], $label);
            $this->assertTrue($status['success'], $label);
        }

        $this->assertSame($before, $this->mailingCount($company));
        $this->assertSame(AppUserMailingStatusEnum::SCHEDULED, $scheduled->refresh()->status);
        Bus::assertNotDispatched(SendAppUsersEmailJob::class);
        Notification::assertNothingSent();
    }

    public function testAMissingAgentFailsClosedForSendTestAndCancel(): void
    {
        Bus::fake();
        $company = Companies::factory()->create();
        $template = $this->template($company);
        $tool = new SendAppUsersEmailTool()
            ->withContext(app(Apps::class), $company, auth()->user())
            ->forRequestingUser(auth()->user());

        $send = $tool->__invoke(mode: 'send', template_name: $template->name, confirm_total: 1);
        $cancel = $tool->__invoke(mode: 'cancel', mailing_id: 1);

        $this->assertSame('denied', $send['outcome']);
        $this->assertSame('denied', $cancel['outcome']);
    }
}
