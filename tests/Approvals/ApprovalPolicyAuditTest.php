<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Actions\DeleteApprovalPolicyAction;
use Kanvas\Approvals\Actions\SaveApprovalPolicyAction;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Approvals\Fixtures\ApprovableOrganization;
use Tests\TestCase;

/**
 * A policy decides who has to sign before money moves, so every change to one is on the ledger with who
 * made it, and only the company's owner or an admin may make it.
 */
final class ApprovalPolicyAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence'];

    private SystemModules $systemModule;

    public function setUp(): void
    {
        parent::setUp();

        $this->systemModule = SystemModules::firstOrCreate([
            'model_name' => ApprovableOrganization::class,
            'apps_id' => app(Apps::class)->getId(),
        ], [
            'name' => 'Approvable Organization',
            'slug' => 'approvable-organization',
        ]);
    }

    public function test_creating_a_policy_records_who_created_it(): void
    {
        $policy = $this->savePolicy(new ApprovalPolicy($this->tenant()), $this->owner());

        $event = $this->lastPolicyEvent($policy);
        $this->assertSame('created', $event->payload['change']);
        $this->assertNull($event->payload['before']);
        $this->assertSame('approve_audit_test', $event->payload['after']['approval_type']);
        $this->assertSame('User', $event->actor_type);
        $this->assertSame($this->owner()->getId(), $event->actor_id);
    }

    public function test_an_edit_records_only_what_changed_before_and_after(): void
    {
        $policy = $this->savePolicy(new ApprovalPolicy($this->tenant()), $this->owner());

        new SaveApprovalPolicyAction($policy, ['notify' => 'none', 'expires_after_hours' => 48], $this->owner())->execute();

        $event = $this->lastPolicyEvent($policy);
        $this->assertSame('updated', $event->payload['change']);
        $this->assertSame(['notify' => 'all', 'expires_after_hours' => null], $event->payload['before']);
        $this->assertSame(['notify' => 'none', 'expires_after_hours' => 48], $event->payload['after']);
    }

    public function test_deleting_a_policy_records_the_policy_it_switched_off(): void
    {
        $policy = $this->savePolicy(new ApprovalPolicy($this->tenant()), $this->owner());

        new DeleteApprovalPolicyAction($policy, $this->owner())->execute();

        $event = $this->lastPolicyEvent($policy);
        $this->assertSame('deleted', $event->payload['change']);
        $this->assertSame('approve_audit_test', $event->payload['before']['approval_type']);
        $this->assertNull($event->payload['after']);
        $this->assertTrue($policy->refresh()->is_deleted);
    }

    public function test_a_write_with_no_person_behind_it_is_filed_against_system(): void
    {
        $policy = ApprovalPolicy::create([...$this->tenant(), ...$this->attributes()]);

        $event = $this->lastPolicyEvent($policy);
        $this->assertSame('System', $event->actor_type);
        $this->assertNull($event->actor_id);
    }

    public function test_someone_who_is_not_owner_or_admin_of_the_company_cannot_change_a_policy(): void
    {
        $policy = $this->savePolicy(new ApprovalPolicy($this->tenant()), $this->owner());
        $stranger = Users::factory()->create(['email' => 'policy-stranger-' . uniqid() . '@example.test']);

        try {
            new SaveApprovalPolicyAction($policy, ['notify' => 'none'], $stranger)->execute();
            $this->fail('A non-admin must not be able to edit a policy.');
        } catch (AuthorizationException) {
        }

        $this->expectException(AuthorizationException::class);

        try {
            new DeleteApprovalPolicyAction($policy, $stranger)->execute();
        } finally {
            $this->assertSame('all', $policy->refresh()->notify);
            $this->assertFalse($policy->is_deleted);
        }
    }

    public function test_the_graphql_mutation_goes_through_the_audited_action(): void
    {
        $response = $this->graphQL('
            mutation($input: ApprovalPolicyInput!) {
                createApprovalPolicy(input: $input) { id approval_type }
            }
        ', ['input' => [
            'system_module_id' => $this->systemModule->getId(),
            'approval_type' => 'approve_audit_test',
            'steps' => [['step' => 1, 'resolver' => 'company_owner', 'required_approvals' => 1]],
        ]]);

        $id = (int) $response->json('data.createApprovalPolicy.id');
        $this->assertGreaterThan(0, $id, (string) $response->getContent());

        $event = $this->lastPolicyEvent(ApprovalPolicy::query()->findOrFail($id));
        $this->assertSame('created', $event->payload['change']);
        $this->assertSame($this->owner()->getId(), $event->actor_id);
    }

    private function savePolicy(ApprovalPolicy $policy, Users $actor): ApprovalPolicy
    {
        return new SaveApprovalPolicyAction($policy, $this->attributes(), $actor)->execute();
    }

    /**
     * @return array<string, int>
     */
    private function tenant(): array
    {
        return [
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->owner()->getCurrentCompany()->getId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return [
            'system_modules_id' => $this->systemModule->getId(),
            'approval_type' => 'approve_audit_test',
            'steps' => [['step' => 1, 'resolver' => 'company_owner', 'required_approvals' => 1]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
            'notify' => 'all',
        ];
    }

    private function owner(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }

    private function lastPolicyEvent(ApprovalPolicy $policy): Event
    {
        /** @var Event $event */
        $event = Event::query()
            ->where('event_type', 'approvals.policy_changed')
            ->where('source_entity_type', ApprovalPolicy::class)
            ->where('source_entity_id', $policy->getId())
            ->latest('id')
            ->firstOrFail();

        return $event;
    }
}
