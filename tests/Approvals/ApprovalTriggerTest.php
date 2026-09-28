<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

final class ApprovalTriggerTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    public function setUp(): void
    {
        parent::setUp();

        ApprovalOriginService::forget();

        SystemModules::firstOrCreate([
            'model_name' => ApprovableOrganization::class,
            'apps_id' => app(Apps::class)->getId(),
        ], [
            'name' => 'Approvable Organization',
            'slug' => 'approvable-organization',
        ]);
    }

    public function test_an_entity_with_no_policy_is_never_gated(): void
    {
        $entity = $this->seedEntity('Ungated Corp');

        $this->assertNull($entity->pendingApproval());
    }

    public function test_an_on_create_policy_gates_the_entity_with_no_intake_code(): void
    {
        $approver = $this->seedApproverUser();
        $this->seedPolicy(ApprovalTriggerEnum::ON_CREATE, $approver);

        $entity = $this->seedEntity('Auto Gated Corp');

        $pending = $entity->pendingApproval();

        $this->assertNotNull($pending, 'Creating the record must open the approval by itself.');
        $this->assertSame(ApprovalStatusEnum::PENDING, $pending->status);
        $this->assertSame([$approver->email], $pending->pendingApproverEmails());
    }

    public function test_a_trigger_condition_that_fails_leaves_the_entity_ungated(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_CREATE, $this->seedApproverUser(), [
            'trigger_condition' => ['field' => 'total_employees', 'operator' => '>=', 'value' => 500],
        ]);

        $this->assertNull($this->seedEntity('Small Corp')->pendingApproval());
    }

    public function test_a_trigger_condition_that_passes_gates_the_entity(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_CREATE, $this->seedApproverUser(), [
            'trigger_condition' => ['field' => 'total_employees', 'operator' => '>=', 'value' => 500],
        ]);

        $this->assertNotNull($this->seedEntity('Big Corp', totalEmployees: 900)->pendingApproval());
    }

    /**
     * The recommended way to gate on provenance: read the record's own data rather than an ambient
     * origin, which cannot be derived from inside a `created` hook.
     */
    public function test_a_condition_can_gate_on_the_entitys_own_provenance_field(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_CREATE, $this->seedApproverUser(), [
            'trigger_condition' => ['field' => 'address', 'operator' => '!=', 'value' => ''],
        ]);

        $this->assertNull($this->seedEntity('No Source Corp')->pendingApproval());
        $this->assertNotNull($this->seedEntity('From Email Corp', address: 'inbound-message-id')->pendingApproval());
    }

    public function test_an_explicit_origin_scope_is_recorded_on_the_request(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_CREATE, $this->seedApproverUser());

        $entity = ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn () => $this->seedEntity('Agent Origin Corp')
        );

        $this->assertSame(ApprovalOriginEnum::AGENT, $entity->pendingApproval()?->origin);
    }

    public function test_an_origin_scope_is_restored_after_the_callback(): void
    {
        ApprovalOriginService::during(ApprovalOriginEnum::EMAIL, fn () => null);

        $this->assertNotSame(ApprovalOriginEnum::EMAIL, ApprovalOriginService::current());
    }

    public function test_a_condition_can_gate_on_an_explicit_origin(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_CREATE, $this->seedApproverUser(), [
            'trigger_condition' => ['field' => 'origin', 'operator' => 'in', 'value' => ['email', 'agent']],
        ]);

        $this->assertNull($this->seedEntity('Plain Corp')->pendingApproval());

        $gated = ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn () => $this->seedEntity('Agent Made Corp')
        );

        $this->assertNotNull($gated->pendingApproval());
    }

    public function test_re_saving_a_gated_entity_does_not_open_a_second_request(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_CREATE, $this->seedApproverUser());

        $entity = $this->seedEntity('Resaved Corp');
        $entity->name = 'Resaved Corp Renamed';
        $entity->saveOrFail();
        $entity->touch();

        $this->assertSame(1, $entity->approvalRequests()->count());
    }

    public function test_an_on_update_policy_does_not_fire_on_create(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_UPDATE, $this->seedApproverUser());

        $entity = $this->seedEntity('Update Only Corp');
        $this->assertNull($entity->pendingApproval());

        $entity->name = 'Update Only Corp Renamed';
        $entity->saveOrFail();

        $this->assertNotNull($entity->pendingApproval());
    }

    /**
     * Kanvas soft-delete is an UPDATE that also fires `updated`. Without a guard, deleting a record
     * would open an approval for it on the way out.
     */
    public function test_soft_deleting_does_not_open_an_approval_via_the_update_hook(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_UPDATE, $this->seedApproverUser());

        $entity = $this->seedEntity('Soft Deleted Corp');
        $entity->softDelete();

        $this->assertSame(0, $entity->approvalRequests()->count());
    }

    public function test_soft_deleting_a_gated_entity_cancels_its_open_request(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_CREATE, $this->seedApproverUser());

        $entity = $this->seedEntity('Cancelled On Delete Corp');
        $request = $entity->pendingApproval();
        $this->assertNotNull($request);

        $entity->softDelete();

        $this->assertSame(ApprovalStatusEnum::CANCELLED, $request->refresh()->status);
        $this->assertNull($entity->pendingApproval());
    }

    public function test_a_broken_policy_does_not_break_the_create_it_was_meant_to_gate(): void
    {
        $this->seedPolicy(ApprovalTriggerEnum::ON_CREATE, $this->seedApproverUser(), [
            'steps' => [['resolver' => 'does_not_exist', 'config' => []]],
        ]);

        $entity = $this->seedEntity('Survives Bad Policy Corp');

        $this->assertTrue($entity->exists, 'A misconfigured policy must never fail the create.');
        $this->assertTrue($entity->pendingApproval()?->isUnassigned());
    }

    private function seedPolicy(
        ApprovalTriggerEnum $trigger,
        Users $approver,
        array $overrides = []
    ): ApprovalPolicy {
        return ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => new ApprovableOrganization()->approvalSystemModuleId(),
            'approval_type' => 'approve_test_entity',
            'steps' => [[
                'resolver' => 'explicit_users',
                'config' => ['users_id' => [$approver->getId()]],
            ]],
            'trigger' => $trigger,
            ...$overrides,
        ]);
    }

    private function seedApproverUser(): Users
    {
        return Users::factory()->create(['email' => 'trigger-approver-' . uniqid() . '@example.test']);
    }

    private function seedEntity(string $name, int $totalEmployees = 0, string $address = ''): ApprovableOrganization
    {
        $user = auth()->user();

        return ApprovableOrganization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => $address,
            'total_employees' => $totalEmployees,
        ]);
    }
}
