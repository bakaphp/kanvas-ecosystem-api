<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Actions\ExpireApprovalRequestsAction;
use Kanvas\Approvals\Actions\NotifyApproversAction;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalDecisionEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Approvals\Notifications\ApprovalRequestedNotification;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
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
