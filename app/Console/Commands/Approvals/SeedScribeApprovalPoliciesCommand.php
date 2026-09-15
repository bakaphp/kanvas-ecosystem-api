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
 * Every policy carries a fallback resolver, and the expense one is why. Its step resolves approvers off
 * the document's vendor Organization, which a bill or an invoice always has — and an expense often does
 * not: a restaurant receipt filed from chat carries a merchant NAME and no organization behind it. With
 * no fallback the request saves with `metadata.unassigned` and nobody can act on it, so the expense
 * never posts and the charge sits in Suspense once the statement row lands. `company_owner` is the
 * default because it is the one resolver that cannot come back empty; point `--fallback-role` at a
 * finance role to stop every small receipt reaching the owner.
 *
 * Trigger is MANUAL deliberately. The intake paths call requestApproval() explicitly, so turning this
 * on does not change when approvals open — only where they are recorded. Switch a tenant to ON_CREATE
 * once its policy has been reviewed.
 */
class SeedScribeApprovalPoliciesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:approvals:seed-scribe-policies {apps_id} {company_id} {--expires-after-hours=} {--fallback-role=}';

    protected $description = 'Creates the AP bill, AR invoice and expense approval policies for one company';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $expires = $this->option('expires-after-hours');
        $fallbackRole = trim((string) $this->option('fallback-role'));

        [$fallbackResolver, $fallbackConfig] = $fallbackRole !== ''
            ? ['role', ['role' => $fallbackRole]]
            : ['company_owner', []];

        // Only the expense policy allows the authority override, and the asymmetry is the point. On a bill
        // the approver list IS the control — "only these people sign" is worthless if an admin can wave one
        // through. An expense is review, not authorization: the card charge already happened, so the
        // signature decides when it posts and under which account, not whether the money leaves.
        $definitions = [
            [Bill::class, 'approve_bill', 'vendor', ApproveAndPushBillHandler::class, false],
            [Invoice::class, 'approve_invoice', 'customer', IssueAndPushInvoiceHandler::class, false],
            [Expense::class, 'approve_expense', 'vendor', ApproveExpenseHandler::class, true],
        ];

        foreach ($definitions as [$model, $approvalType, $relation, $handler, $allowAuthorityOverride]) {
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
                'fallback_resolver' => $fallbackResolver,
                'fallback_config' => $fallbackConfig,
                'allow_authority_override' => $allowAuthorityOverride,
                'trigger' => ApprovalTriggerEnum::MANUAL,
                'reject_policy' => 'any',
                'notify' => 'none',
                'expires_after_hours' => $expires !== null ? (int) $expires : null,
            ]);

            // firstOrCreate leaves an existing row untouched, and a null fallback_resolver is the tell
            // that nobody has chosen one — so backfilling both settings there cannot overwrite a
            // deliberate choice. This is also what rescues requests ALREADY sitting unassigned:
            // ApproverSelfAssignService reads the override off the policy when someone tries to DECIDE,
            // not when the request was written, so flipping it here reaches backwards.
            $backfilled = false;
            if (! $policy->wasRecentlyCreated && $policy->fallback_resolver === null) {
                $policy->fallback_resolver = $fallbackResolver;
                $policy->fallback_config = $fallbackConfig;
                $policy->allow_authority_override = $allowAuthorityOverride;
                $policy->saveOrFail();
                $backfilled = true;
            }

            $this->info(sprintf(
                '%s policy %s (id %d) for company %d — fallback %s.',
                $approvalType,
                $policy->wasRecentlyCreated ? 'created' : 'already existed',
                $policy->getId(),
                $company->getId(),
                $backfilled ? "backfilled to {$fallbackResolver}" : (string) $policy->fallback_resolver,
            ));
        }

        return self::SUCCESS;
    }
}
