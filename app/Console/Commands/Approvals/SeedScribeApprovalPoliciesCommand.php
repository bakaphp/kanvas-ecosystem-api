<?php

declare(strict_types=1);

namespace App\Console\Commands\Approvals;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Approvals\ApproveAndPushBillHandler;
use Kanvas\Connectors\Acumatica\Approvals\IssueAndPushInvoiceHandler;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Approvals\ApproveExpenseHandler;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

/**
 * Creates the two AP/AR policies that reproduce today's behaviour exactly: one step, the vendor's or
 * customer's own approver, one signature, the Acumatica push as the handler.
 *
 * The handlers live in the Acumatica connector, not in Scribe: approving is domain work, pushing is
 * connector work, and naming the handler here is what makes the ERP dependency explicit — a tenant on
 * a different ERP seeds the same policies with a different handler class and nothing else changes.
 *
 * `notify` is 'none' on purpose: the Scribe intake paths still send the Slack DM (with the invoice PDF
 * attached), and letting the generic layer also mail every approver would double-notify real people.
 * Flip it to 'all' only once the Slack notification moves into the approvals domain.
 *
 * Trigger is MANUAL deliberately. The intake paths call requestApproval() explicitly, so turning this
 * on does not change when approvals open — only where they are recorded. Switch a tenant to ON_CREATE
 * once its policy has been reviewed.
 */
class SeedScribeApprovalPoliciesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:approvals:seed-scribe-policies {apps_id} {company_id} {--expires-after-hours=}';

    protected $description = 'Creates the AP bill, AR invoice and expense approval policies for one company';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $expires = $this->option('expires-after-hours');

        $definitions = [
            [Bill::class, 'approve_bill', 'vendor', ApproveAndPushBillHandler::class],
            [Invoice::class, 'approve_invoice', 'customer', IssueAndPushInvoiceHandler::class],
            [Expense::class, 'approve_expense', 'vendor', ApproveExpenseHandler::class],
        ];

        foreach ($definitions as [$model, $approvalType, $relation, $handler]) {
            $systemModule = SystemModulesRepository::getByModelName($model, $app);

            $policy = ApprovalPolicy::firstOrCreate([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
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
                'trigger' => ApprovalTriggerEnum::MANUAL,
                'reject_policy' => 'any',
                'notify' => 'none',
                'expires_after_hours' => $expires !== null ? (int) $expires : null,
            ]);

            $this->info(sprintf(
                '%s policy %s (id %d) for company %d.',
                $approvalType,
                $policy->wasRecentlyCreated ? 'created' : 'already existed',
                $policy->getId(),
                $company->getId()
            ));
        }

        return self::SUCCESS;
    }
}
