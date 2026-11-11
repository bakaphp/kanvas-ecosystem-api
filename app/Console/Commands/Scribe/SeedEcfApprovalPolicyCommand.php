<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Approvals\Actions\SeedEcfApprovalPoliciesAction;

/**
 * Switches an e-CF company onto "an agent never sends an e-CF to DGII without a person" (plan §2).
 *
 * Two policies, not one with two steps: turning approval off for invoices means editing the
 * approve_invoice step's `when` (or deleting that policy), and that can never reach credit notes, which
 * stay gated for agents always. Both steps carry `when origin == agent`, so a person issuing from the UI
 * or the API is never asked — and a request no step applies to is closed by rule and issued
 * (SubmitInvoiceForApprovalAction), never left pending.
 *
 * An existing approve_invoice policy (an Acumatica tenant's) is left untouched unless --force.
 */
class SeedEcfApprovalPolicyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-scribe:seed-ecf-approval-policy {app_id} {company_id} {--force : Replace existing invoice/credit-note policies}';

    protected $description = 'Creates the agent-only e-CF approval policies (invoices, credit notes) for one company';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        $results = new SeedEcfApprovalPoliciesAction(
            app: $app,
            company: $company,
            force: (bool) $this->option('force'),
        )->execute();

        foreach ($results as $result) {
            if (! $result['saved']) {
                $this->warn("{$result['approval_type']} policy already exists (id {$result['policy']->getId()}); left untouched. Use --force to replace it.");

                continue;
            }

            $this->info("{$result['approval_type']} policy saved (id {$result['policy']->getId()}) for company {$company->getId()}: agent-originated only, admins approve, owner as fallback, digest notifications.");
        }

        return self::SUCCESS;
    }
}
