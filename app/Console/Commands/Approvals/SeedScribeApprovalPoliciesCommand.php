<?php

declare(strict_types=1);

namespace App\Console\Commands\Approvals;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Approvals\Actions\SeedScribeApprovalPoliciesAction;

/**
 * Creates the two AP/AR policies that reproduce today's behaviour exactly: one step, the vendor's or
 * customer's own approver, one signature, the Acumatica push as the handler.
 *
 * The handlers live in the Acumatica connector, not in Scribe: approving is domain work, pushing is
 * connector work, and naming the handler here is what makes the ERP dependency explicit — a tenant on
 * a different ERP seeds the same policies with a different handler class and nothing else changes.
 *
 * `notify` is 'all': the approvals domain is the only notifier, and Bill/Invoice deliver it as a Slack DM
 * from the AP/AR agent (PDF attached) when the approver is on Slack, email otherwise.
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

        $results = new SeedScribeApprovalPoliciesAction(
            app: $app,
            company: $company,
            fallbackRole: (string) $this->option('fallback-role'),
            expiresAfterHours: $expires !== null ? (int) $expires : null,
        )->execute();

        foreach ($results as $result) {
            $this->info(sprintf(
                '%s policy %s (id %d) for company %d — fallback %s.',
                $result['approval_type'],
                $result['created'] ? 'created' : 'already existed',
                $result['policy']->getId(),
                $company->getId(),
                $result['fallback_changed'] ? "backfilled to {$result['policy']->fallback_resolver}" : (string) $result['policy']->fallback_resolver,
            ));
        }

        return self::SUCCESS;
    }
}
