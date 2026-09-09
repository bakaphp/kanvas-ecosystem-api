<?php

declare(strict_types=1);

namespace Tests\Scribe\Approvals;

use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Tests\Scribe\ScribeTestCase;

/**
 * The command IS the deploy step: everything else in this change is inert until it runs, and the branch
 * that matters most is the backfill, because a tenant seeded before fallbacks existed is exactly the
 * tenant whose expenses are stranded right now.
 */
final class SeedScribeApprovalPoliciesCommandTest extends ScribeTestCase
{
    private const string COMMAND = 'kanvas:approvals:seed-scribe-policies';

    public function test_a_fresh_expense_policy_can_always_find_someone_to_approve(): void
    {
        $this->runCommand();

        $policy = $this->policyFor(Expense::class);

        $this->assertSame('company_owner', $policy->fallback_resolver);
        $this->assertTrue($policy->allow_authority_override);
    }

    /**
     * The asymmetry is deliberate. On a bill the approver list IS the control, so an admin must not be
     * able to wave one through; an expense is review of a charge that already happened.
     */
    public function test_a_bill_policy_does_not_get_the_authority_override(): void
    {
        $this->runCommand();

        $this->assertFalse($this->policyFor(Bill::class)->allow_authority_override);
    }

    /**
     * firstOrCreate leaves an existing row untouched, so without this branch every already-seeded tenant
     * keeps stranding requests no matter how many times the command is run.
     */
    public function test_re_running_backfills_a_policy_seeded_before_fallbacks_existed(): void
    {
        $this->runCommand();

        $policy = $this->policyFor(Expense::class);
        $policy->fallback_resolver = null;
        $policy->fallback_config = null;
        $policy->allow_authority_override = false;
        $policy->saveOrFail();

        $this->runCommand();

        $policy->refresh();
        $this->assertSame('company_owner', $policy->fallback_resolver);
        $this->assertTrue($policy->allow_authority_override);
    }

    public function test_a_fallback_somebody_chose_is_never_overwritten(): void
    {
        $this->runCommand();

        $policy = $this->policyFor(Expense::class);
        $policy->fallback_resolver = 'explicit_users';
        $policy->fallback_config = ['user_ids' => [static::$cachedUser->getId()]];
        $policy->saveOrFail();

        $this->runCommand();

        $this->assertSame('explicit_users', $policy->refresh()->fallback_resolver);
    }

    public function test_a_finance_role_can_catch_the_receipts_instead_of_the_owner(): void
    {
        $this->runCommand(fallbackRole: 'Finance');

        $policy = $this->policyFor(Expense::class);

        $this->assertSame('role', $policy->fallback_resolver);
        $this->assertSame(['role' => 'Finance'], $policy->fallback_config);
    }

    private function runCommand(?string $fallbackRole = null): void
    {
        $arguments = [
            'apps_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ];

        if ($fallbackRole !== null) {
            $arguments['--fallback-role'] = $fallbackRole;
        }

        $this->artisan(self::COMMAND, $arguments)->assertSuccessful();
    }

    private function policyFor(string $model): ApprovalPolicy
    {
        return ApprovalPolicy::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where(
                'system_modules_id',
                SystemModulesRepository::getByModelName($model, $this->kanvasApp)->getId()
            )
            ->firstOrFail();
    }
}
