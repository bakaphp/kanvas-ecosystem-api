<?php

declare(strict_types=1);

namespace App\Console\Commands\Approvals;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Approvals\MessageApprovalPolicyService;

/**
 * Creates the agent-message policy up front, so an operator can review and tighten who signs off
 * before the first draft is held rather than after. The request path provisions the same default on
 * its own, so this is a convenience, never a prerequisite.
 */
class SeedMessageApprovalPolicyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:approvals:seed-message-policy {apps_id} {company_id}';

    protected $description = 'Creates the agent-message approval policy for one company';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));

        $policy = MessageApprovalPolicyService::create($app, $company);

        $this->info(sprintf(
            'agent_message policy %s (id %d) for company %d.',
            $policy->wasRecentlyCreated ? 'created' : 'already existed',
            $policy->getId(),
            $company->getId()
        ));

        return self::SUCCESS;
    }
}
