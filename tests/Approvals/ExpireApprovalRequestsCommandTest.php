<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kanvas\Approvals\Actions\RequestApprovalAction;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

final class ExpireApprovalRequestsCommandTest extends TestCase
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

    public function test_the_command_expires_a_past_due_request(): void
    {
        $request = $this->seedExpiringRequest();

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->artisan('kanvas:approvals:expire', ['--apps_id' => app(Apps::class)->getId()])
            ->assertSuccessful();

        $this->assertSame(ApprovalStatusEnum::EXPIRED, $request->refresh()->status);

        Carbon::setTestNow();
    }

    /**
     * The sweep loops apps, and RoleApproverResolver reads Bouncer-scoped roles. The worker process is
     * long-lived, so without a per-iteration rebind the scope left by the previous app leaks into the
     * next one — the failure mode that silently skipped 90 tenants in the daily-learning digest.
     */
    public function test_the_command_rebinds_the_app_scope_for_each_app_it_visits(): void
    {
        $bootApp = app(Apps::class);

        $lastApp = Apps::query()
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->get()
            ->last();

        $this->assertNotSame(
            $bootApp->getId(),
            $lastApp->getId(),
            'This test is only meaningful when more than one app exists to iterate.'
        );

        $this->artisan('kanvas:approvals:expire')->assertSuccessful();

        // Drop overwriteAppService from the command and this stays at the boot app instead.
        $this->assertSame(
            $lastApp->getId(),
            app(Apps::class)->getId(),
            'The container app must have been rebound per iteration, not left bound at boot.'
        );
    }

    public function test_the_command_reports_when_there_is_nothing_to_expire(): void
    {
        $this->artisan('kanvas:approvals:expire', ['--apps_id' => app(Apps::class)->getId()])
            ->expectsOutputToContain('approval request(s) expired.')
            ->assertSuccessful();
    }

    private function seedExpiringRequest(): ApprovalRequest
    {
        $user = auth()->user();

        $entity = ApprovableOrganization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => 'Command Expiry Corp ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);

        $approver = Users::factory()->create(['email' => 'cmd-approver-' . uniqid() . '@example.test']);

        $policy = ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'system_modules_id' => new ApprovableOrganization()->approvalSystemModuleId(),
            'approval_type' => 'approve_test_entity',
            'steps' => [[
                'resolver' => 'explicit_users',
                'config' => ['users_id' => [$approver->getId()]],
            ]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
            'expires_after_hours' => 1,
        ]);

        return new RequestApprovalAction(
            entity: $entity,
            policy: $policy,
            origin: ApprovalOriginEnum::AGENT,
        )->execute()->refresh();
    }
}
