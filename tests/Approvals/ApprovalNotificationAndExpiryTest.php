<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Actions\ExpireApprovalRequestsAction;
use Kanvas\Approvals\Actions\NotifyApproversAction;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalEmailTemplateEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Jobs\SendApprovalDigestJob;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Notifications\ApprovalDigestNotification;
use Kanvas\Approvals\Notifications\ApprovalRequestedNotification;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

final class ApprovalNotificationAndExpiryTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    public function setUp(): void
    {
        parent::setUp();

        NotificationFacade::fake();

        SystemModules::firstOrCreate([
            'model_name' => ApprovableOrganization::class,
            'apps_id' => app(Apps::class)->getId(),
        ], [
            'name' => 'Approvable Organization',
            'slug' => 'approvable-organization',
        ]);
    }

    public function test_opening_a_request_notifies_the_live_steps_approvers(): void
    {
        [$request, $approvers] = $this->chain([['approvers' => 2]]);

        NotificationFacade::assertSentTo($approvers[0], ApprovalRequestedNotification::class);
        NotificationFacade::assertSentTo($approvers[1], ApprovalRequestedNotification::class);
        $this->assertSame(2, $request->approvers()->whereNotNull('notified_at')->count());
    }

    public function test_approvers_queued_behind_an_unfinished_step_are_not_notified(): void
    {
        [$request, $approvers] = $this->chain([['approvers' => 1], ['approvers' => 1]]);

        NotificationFacade::assertSentTo($approvers[0], ApprovalRequestedNotification::class);
        NotificationFacade::assertNotSentTo($approvers[1], ApprovalRequestedNotification::class);
        $this->assertNull($request->approvers()->where('step', 2)->first()->notified_at);
    }

    public function test_advancing_a_step_notifies_the_newly_live_approvers(): void
    {
        [$request, $approvers] = $this->chain([['approvers' => 1], ['approvers' => 1]]);

        new ApproveAction($request, $approvers[0])->execute();

        NotificationFacade::assertSentTo($approvers[1], ApprovalRequestedNotification::class);
        $this->assertNotNull($request->refresh()->approvers()->where('step', 2)->first()->notified_at);
    }

    public function test_notify_mode_first_caps_the_blast_radius(): void
    {
        [$request] = $this->chain([['approvers' => 3]], ['notify' => 'first']);

        $this->assertSame(1, $request->approvers()->whereNotNull('notified_at')->count());
    }

    public function test_notify_mode_none_sends_nothing(): void
    {
        [$request, $approvers] = $this->chain([['approvers' => 2]], ['notify' => 'none']);

        NotificationFacade::assertNotSentTo($approvers[0], ApprovalRequestedNotification::class);
        $this->assertSame(0, $request->approvers()->whereNotNull('notified_at')->count());
    }

    public function test_notify_mode_digest_sends_nothing_now_and_schedules_one_digest_per_approver(): void
    {
        Queue::fake();
        $approver = $this->approver();
        $policy = $this->digestPolicy($approver);

        $requests = array_map(fn () => $this->openRequest($policy), range(1, 3));

        NotificationFacade::assertNothingSentTo($approver);
        Queue::assertPushed(SendApprovalDigestJob::class, 1);
        Queue::assertPushed(
            SendApprovalDigestJob::class,
            fn (SendApprovalDigestJob $job): bool => $job->user->is($approver) && $job->delay !== null
        );
        $this->assertSame(0, $requests[0]->approvers()->whereNotNull('notified_at')->count());
    }

    public function test_the_digest_sends_one_email_for_every_request_in_the_window(): void
    {
        Queue::fake();
        $approver = $this->approver();
        $policy = $this->digestPolicy($approver);
        $requests = array_map(fn () => $this->openRequest($policy), range(1, 3));

        $this->runDigest($approver);

        NotificationFacade::assertSentToTimes($approver, ApprovalDigestNotification::class, 1);
        NotificationFacade::assertSentTo(
            $approver,
            ApprovalDigestNotification::class,
            fn (ApprovalDigestNotification $digest): bool => str_contains($digest->getEmailContent(), '3 approvals waiting for you')
                && substr_count($digest->getEmailContent(), 'Digest Corp') === 3
        );
        NotificationFacade::assertNotSentTo($approver, ApprovalRequestedNotification::class);
        foreach ($requests as $request) {
            $this->assertNotNull($request->approvers()->where('users_id', $approver->getId())->first()->notified_at);
        }
    }

    public function test_a_digest_with_a_single_request_sends_the_regular_email(): void
    {
        Queue::fake();
        $approver = $this->approver();
        $this->openRequest($this->digestPolicy($approver));

        $this->runDigest($approver);

        NotificationFacade::assertSentToTimes($approver, ApprovalRequestedNotification::class, 1);
        NotificationFacade::assertNotSentTo($approver, ApprovalDigestNotification::class);
    }

    public function test_a_request_opened_after_the_digest_ran_starts_a_new_window(): void
    {
        Queue::fake();
        $approver = $this->approver();
        $policy = $this->digestPolicy($approver);
        $this->openRequest($policy);
        $this->runDigest($approver);

        $late = $this->openRequest($policy);

        Queue::assertPushed(SendApprovalDigestJob::class, 2);
        $this->runDigest($approver);
        $this->assertNotNull($late->approvers()->where('users_id', $approver->getId())->first()->notified_at);
        NotificationFacade::assertSentToTimes($approver, ApprovalRequestedNotification::class, 2);
    }

    public function test_the_digest_leaves_requests_of_an_immediate_policy_alone(): void
    {
        Queue::fake();
        $approver = $this->approver();
        $immediate = $this->openRequest($this->digestPolicy($approver, ['notify' => 'all', 'approval_type' => 'approve_now']));
        NotificationFacade::assertSentToTimes($approver, ApprovalRequestedNotification::class, 1);

        $this->runDigest($approver);

        NotificationFacade::assertSentToTimes($approver, ApprovalRequestedNotification::class, 1);
        $this->assertNotNull($immediate->approvers()->first()->notified_at);
    }

    public function test_without_a_template_the_email_falls_back_to_readable_html(): void
    {
        $request = $this->openRequest($this->digestPolicy($this->approver(), ['notify' => 'none']));

        $content = new ApprovalRequestedNotification($request)->getEmailContent();

        $this->assertStringContainsString(e($request->resolveEntity()->name), $content);
        $this->assertStringContainsString('Approve Digest Entity', $content);
        $this->assertStringNotContainsString('\\', $content, 'The record type must not be a class name.');
    }

    public function test_a_company_template_replaces_the_built_in_email(): void
    {
        $company = auth()->user()->getCurrentCompany();
        new CreateTemplateAction(new TemplateInput(
            app: app(Apps::class),
            name: ApprovalEmailTemplateEnum::DIGEST->value,
            template: '<p>Custom: {{ count }} for {{ company_name }} — {% for approval in approvals %}[{{ approval.name }}]{% endfor %}</p>',
            company: $company,
        ))->execute();
        $policy = $this->digestPolicy($this->approver(), ['notify' => 'none']);
        $requests = collect([$this->openRequest($policy), $this->openRequest($policy)]);

        $content = new ApprovalDigestNotification($company, $requests)->getEmailContent();

        $this->assertStringContainsString('Custom: 2 for ' . e($company->name), $content);
        $this->assertStringContainsString('[' . e($requests[0]->resolveEntity()->name) . ']', $content);
    }

    public function test_a_second_run_does_not_re_notify_the_same_approver(): void
    {
        [$request] = $this->chain([['approvers' => 2]]);

        $this->assertSame(0, new NotifyApproversAction($request->refresh())->execute());
    }

    public function test_a_past_due_request_expires_and_skips_everyone_still_waiting(): void
    {
        [$request] = $this->chain([['approvers' => 2]], ['expires_after_hours' => 1]);

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $expired = new ExpireApprovalRequestsAction(app(Apps::class))->execute();

        $this->assertGreaterThanOrEqual(1, $expired);
        $this->assertSame(ApprovalStatusEnum::EXPIRED, $request->refresh()->status);
        $this->assertSame(
            0,
            $request->approvers()->whereIn('decision', [
                ApprovalDecisionEnum::PENDING,
                ApprovalDecisionEnum::WAITING,
            ])->count()
        );

        Carbon::setTestNow();
    }

    public function test_a_request_that_is_not_due_yet_is_left_alone(): void
    {
        [$request] = $this->chain([['approvers' => 1]], ['expires_after_hours' => 72]);

        new ExpireApprovalRequestsAction(app(Apps::class))->execute();

        $this->assertSame(ApprovalStatusEnum::PENDING, $request->refresh()->status);
    }

    public function test_a_request_with_no_expiry_never_expires(): void
    {
        [$request] = $this->chain([['approvers' => 1]]);

        Carbon::setTestNow(Carbon::now()->addYear());

        new ExpireApprovalRequestsAction(app(Apps::class))->execute();

        $this->assertSame(ApprovalStatusEnum::PENDING, $request->refresh()->status);

        Carbon::setTestNow();
    }

    /**
     * An approver deciding in the same instant as the sweep must win, not be overwritten by it.
     */
    public function test_a_request_resolved_before_the_sweep_is_not_expired(): void
    {
        [$request, $approvers] = $this->chain([['approvers' => 1]], ['expires_after_hours' => 1]);

        new ApproveAction($request, $approvers[0])->execute();

        Carbon::setTestNow(Carbon::now()->addHours(2));

        new ExpireApprovalRequestsAction(app(Apps::class))->execute();

        $this->assertSame(ApprovalStatusEnum::APPROVED, $request->refresh()->status);

        Carbon::setTestNow();
    }

    /**
     * @return array{0: ApprovalRequest, 1: list<Users>}
     */
    private function chain(array $stepSpecs, array $policyOverrides = []): array
    {
        $entity = $this->seedEntity('Notify Corp ' . uniqid());
        $approvers = [];
        $steps = [];

        foreach ($stepSpecs as $index => $spec) {
            $ids = [];

            for ($i = 0; $i < ($spec['approvers'] ?? 1); $i++) {
                $user = Users::factory()->create([
                    'email' => 'notify-' . $index . '-' . $i . '-' . uniqid() . '@example.test',
                ]);
                $approvers[] = $user;
                $ids[] = $user->getId();
            }

            $steps[] = [
                'step' => $index + 1,
                'resolver' => 'explicit_users',
                'config' => ['users_id' => $ids],
                'required_approvals' => 1,
            ];
        }

        $policy = ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => new ApprovableOrganization()->approvalSystemModuleId(),
            'approval_type' => 'approve_test_entity',
            'steps' => $steps,
            'trigger' => ApprovalTriggerEnum::MANUAL,
            ...$policyOverrides,
        ]);

        $request = new RequestApprovalAction(
            entity: $entity,
            policy: $policy,
            origin: ApprovalOriginEnum::AGENT,
        )->execute()->refresh();

        return [$request, $approvers];
    }

    private function approver(): Users
    {
        return Users::factory()->create(['email' => 'digest-' . uniqid() . '@example.test']);
    }

    private function digestPolicy(Users $approver, array $overrides = []): ApprovalPolicy
    {
        return ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => new ApprovableOrganization()->approvalSystemModuleId(),
            'approval_type' => 'approve_digest_entity',
            'steps' => [[
                'step' => 1,
                'resolver' => 'explicit_users',
                'config' => ['users_id' => [$approver->getId()]],
                'required_approvals' => 1,
            ]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
            'notify' => 'digest',
            ...$overrides,
        ]);
    }

    private function openRequest(ApprovalPolicy $policy): ApprovalRequest
    {
        return new RequestApprovalAction(
            entity: $this->seedEntity('Digest Corp ' . uniqid()),
            policy: $policy,
            origin: ApprovalOriginEnum::AGENT,
        )->execute()->refresh();
    }

    private function runDigest(Users $approver): void
    {
        new SendApprovalDigestJob(app(Apps::class), auth()->user()->getCurrentCompany(), $approver)->handle();
    }

    private function seedEntity(string $name): ApprovableOrganization
    {
        $user = auth()->user();

        return ApprovableOrganization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
