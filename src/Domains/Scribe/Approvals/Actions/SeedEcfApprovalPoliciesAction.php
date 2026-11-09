<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Approvals\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Approvals\Enums\ApprovalNotifyModeEnum;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Approvals\IssuePurchaseEcfHandler;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Approvals\IssueInvoiceHandler;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

/**
 * "An agent never sends an e-CF to DGII without a person" (plan §2): approve_invoice,
 * approve_credit_note and approve_debit_note on invoices, approve_purchase_ecf on bills and expenses, each
 * one step `when origin == agent`. Rationale on SeedEcfApprovalPolicyCommand.
 *
 * An existing policy is left alone unless $force: an Acumatica tenant's approve_invoice is its own.
 */
class SeedEcfApprovalPoliciesAction
{
    public const array APPROVAL_TYPES = ['approve_invoice', 'approve_credit_note', 'approve_debit_note'];

    /**
     * The purchase e-CF (E41/E43/E47) is issued from a bill or an expense, so its policy hangs off those
     * modules with its own handler; same agent-only step as the sales side.
     */
    public const array PURCHASE_MODELS = [Bill::class, Expense::class];

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly ?Users $defaultApprover = null,
        private readonly bool $force = false,
    ) {
    }

    /**
     * @return list<array{approval_type: string, policy: ApprovalPolicy, saved: bool}>
     */
    public function execute(): array
    {
        [$fallbackResolver, $fallbackConfig] = SeedScribeApprovalPoliciesAction::fallbackFor($this->defaultApprover);

        $policies = [
            ...array_map(fn (string $type): array => [Invoice::class, $type, IssueInvoiceHandler::class], self::APPROVAL_TYPES),
            ...array_map(
                fn (string $model): array => [$model, EcfIssuanceService::PURCHASE_APPROVAL_TYPE, IssuePurchaseEcfHandler::class],
                self::PURCHASE_MODELS
            ),
        ];

        $results = [];
        foreach ($policies as [$modelClass, $approvalType, $handler]) {
            $systemModule = SystemModulesRepository::getByModelName($modelClass, $this->app);
            $policy = ApprovalPolicy::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->where('system_modules_id', $systemModule->getId())
                ->where('approval_type', $approvalType)
                ->notDeleted()
                ->first();

            if ($policy !== null && ! $this->force) {
                $results[] = ['approval_type' => $approvalType, 'policy' => $policy, 'saved' => false];

                continue;
            }

            $policy ??= new ApprovalPolicy([
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'system_modules_id' => $systemModule->getId(),
                'approval_type' => $approvalType,
            ]);
            $policy->fill([
                'steps' => [[
                    'step' => 1,
                    'resolver' => 'role',
                    'config' => ['role' => RolesEnums::ADMIN->value],
                    'required_approvals' => 1,
                    'when' => ['field' => 'origin', 'operator' => '==', 'value' => ApprovalOriginEnum::AGENT->value],
                ]],
                'handler' => $handler,
                'fallback_resolver' => $fallbackResolver,
                'fallback_config' => $fallbackConfig,
                'allow_authority_override' => false,
                'trigger' => ApprovalTriggerEnum::MANUAL,
                'reject_policy' => 'any',
                'notify' => ApprovalNotifyModeEnum::DIGEST->value,
                'expires_after_hours' => null,
            ]);
            $policy->saveOrFail();

            $results[] = ['approval_type' => $approvalType, 'policy' => $policy, 'saved' => true];
        }

        return $results;
    }
}
