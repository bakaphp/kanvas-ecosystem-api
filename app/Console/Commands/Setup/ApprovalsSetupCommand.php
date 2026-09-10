<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Baka\Traits\KanvasJobsTrait;
use Database\Seeders\CustomerUpdateEmailTemplateSeeder;
use Illuminate\Console\Command;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Approvals\MessageApprovalPolicyService;
use Kanvas\Social\Messages\Support\MessageApproval;
use Kanvas\Users\Models\Users;

/**
 * Turns agent-message approvals on for a company.
 *
 * Nothing here is a prerequisite — the request path provisions the same policy on the first held
 * draft, and the renderer falls back to a built-in shell with no template row. This exists so an
 * operator can review and tighten who signs off *before* the first draft is held rather than after.
 *
 * Safe to re-run. An existing policy is repaired, not skipped: one seeded before
 * `allow_authority_override` existed keeps the column's false default, and on a tenant whose channels
 * have no members that leaves nobody able to approve anything.
 */
class ApprovalsSetupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-approvals:setup
        {app_id}
        {user_id}
        {company_id? : omit when using --all}
        {--all : repair every company on the app that already has an agent-message policy}';

    protected $description = 'Initialize agent-message approvals for a company — email template + approval policy';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));

        // The policy row carries no user, but every sibling setup command takes one — resolving it
        // keeps the signature uniform and still fails fast on a bad id.
        Users::getById((int) $this->argument('user_id'));

        $this->overwriteAppService($app);

        // Global (apps_id 0), so it is seeded once rather than per company — but running it here too
        // means an environment that will not re-run db:seed still gets the layout.
        new CustomerUpdateEmailTemplateSeeder()->run();
        $this->info('Customer-update email template ensured.');

        return (bool) $this->option('all')
            ? $this->repairAll($app)
            : $this->setupOne($app);
    }

    private function setupOne(Apps $app): int
    {
        $companyId = (int) $this->argument('company_id');

        if ($companyId <= 0) {
            $this->error('Pass a company_id, or --all to repair every company that already has a policy.');

            return self::FAILURE;
        }

        $company = Companies::getById($companyId);

        $this->report(MessageApprovalPolicyService::create($app, $company));

        return self::SUCCESS;
    }

    /**
     * Only companies that ALREADY have a policy. Creating one for every company on the app would gate
     * agents for tenants who never asked for approval mode.
     */
    private function repairAll(Apps $app): int
    {
        $policies = ApprovalPolicy::query()
            ->where('apps_id', $app->getId())
            ->where('approval_type', MessageApproval::APPROVAL_TYPE)
            ->notDeleted()
            ->get();

        if ($policies->isEmpty()) {
            $this->info('No agent-message policies on app ' . $app->getId() . ' — nothing to repair.');

            return self::SUCCESS;
        }

        foreach ($policies as $policy) {
            $this->report($policy);
        }

        $this->info($policies->count() . ' policy(ies) checked.');

        return self::SUCCESS;
    }

    private function report(ApprovalPolicy $policy): void
    {
        if ($policy->wasRecentlyCreated) {
            $this->info(sprintf('  created  policy %d for company %d.', $policy->getId(), $policy->companies_id));

            return;
        }

        $fixed = MessageApprovalPolicyService::repair($policy);

        $this->info(sprintf(
            '  %-8s policy %d for company %d%s',
            $fixed === [] ? 'ok' : 'repaired',
            $policy->getId(),
            $policy->companies_id,
            $fixed === [] ? '' : ' — ' . implode(', ', $fixed)
        ));
    }
}
