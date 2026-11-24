<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Connectors\Acumatica\Approvals\ApproveAndPushBillHandler;
use Kanvas\Connectors\Acumatica\Approvals\IssueAndPushInvoiceHandler;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Approvals\ApproveExpenseHandler;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

/**
 * The AP bill, AR invoice and expense policies: one step, the vendor's or customer's own approver, one
 * signature. Behaviour and rationale live on SeedScribeApprovalPoliciesCommand, which runs this.
 *
 * The fallback is who signs when the vendor or customer names nobody: the default approver when one is
 * given (setup's completing admin), else a role, else the company owner. A fallback this action wrote
 * itself (none, the owner, or an earlier default approver) moves to a newly given default approver; a
 * role someone chose is left alone.
 */
class SeedScribeApprovalPoliciesAction
{
    public const string FALLBACK_EXPLICIT_USERS = 'explicit_users';
    public const string FALLBACK_COMPANY_OWNER = 'company_owner';

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly ?Users $defaultApprover = null,
        private readonly ?string $fallbackRole = null,
        private readonly ?int $expiresAfterHours = null,
    ) {
    }

    /**
     * @return list<array{approval_type: string, policy: ApprovalPolicy, created: bool, fallback_changed: bool}>
     */
    public function execute(): array
    {
        [$fallbackResolver, $fallbackConfig] = self::fallbackFor($this->defaultApprover, $this->fallbackRole);

        // Only the expense policy allows the authority override: on a bill the approver list IS the
        // control, an expense is review of money already spent.
        $definitions = [
            [Bill::class, 'approve_bill', 'vendor', ApproveAndPushBillHandler::class, false],
            [Invoice::class, 'approve_invoice', 'customer', IssueAndPushInvoiceHandler::class, false],
            [Expense::class, 'approve_expense', 'vendor', ApproveExpenseHandler::class, true],
        ];

        $results = [];
        foreach ($definitions as [$model, $approvalType, $relation, $handler, $allowAuthorityOverride]) {
            $systemModule = SystemModulesRepository::getByModelName($model, $this->app);

            $policy = ApprovalPolicy::firstOrCreate([
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'system_modules_id' => $systemModule->getId(),
                'approval_type' => $approvalType,
            ], [
                'steps' => [[
                    'step' => 1,
                    'resolver' => 'organization_approver',
                    'config' => ['relation' => $relation],
                    'required_approvals' => 1,
                ]],
                'handler' => $handler,
                'fallback_resolver' => $fallbackResolver,
                'fallback_config' => $fallbackConfig,
                'allow_authority_override' => $allowAuthorityOverride,
                'trigger' => ApprovalTriggerEnum::MANUAL,
                'reject_policy' => 'any',
                'notify' => 'all',
                'expires_after_hours' => $this->expiresAfterHours,
            ]);

            $changed = false;
            if (! $policy->wasRecentlyCreated && $this->mayReplaceFallback($policy)) {
                // A null fallback means nobody ever chose one, so the override default comes with it.
                if ($policy->fallback_resolver === null) {
                    $policy->allow_authority_override = $allowAuthorityOverride;
                }
                $policy->fallback_resolver = $fallbackResolver;
                $policy->fallback_config = $fallbackConfig;
                if ($policy->isDirty()) {
                    $policy->saveOrFail();
                    $changed = true;
                }
            }

            $results[] = [
                'approval_type' => $approvalType,
                'policy' => $policy,
                'created' => $policy->wasRecentlyCreated,
                'fallback_changed' => $changed,
            ];
        }

        return $results;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function fallbackFor(?Users $defaultApprover, ?string $fallbackRole = null): array
    {
        if ($defaultApprover !== null) {
            return [self::FALLBACK_EXPLICIT_USERS, ['users_id' => [$defaultApprover->getId()]]];
        }

        $role = Str::trimToNull($fallbackRole);
        if ($role !== null) {
            return ['role', ['role' => $role]];
        }

        return [self::FALLBACK_COMPANY_OWNER, []];
    }

    private function mayReplaceFallback(ApprovalPolicy $policy): bool
    {
        if ($policy->fallback_resolver === null) {
            return true;
        }

        return $this->defaultApprover !== null
            && in_array($policy->fallback_resolver, [self::FALLBACK_COMPANY_OWNER, self::FALLBACK_EXPLICIT_USERS], true);
    }
}
