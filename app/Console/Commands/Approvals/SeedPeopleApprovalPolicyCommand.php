<?php

declare(strict_types=1);

namespace App\Console\Commands\Approvals;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

/**
 * Seeds the 'approve_people' policy that gates a People before it is pushed to Salesforce
 * (RequestPeopleApprovalActivity / PushApprovedPeopleActivity). Trigger is MANUAL on purpose — the
 * Activity calls requestApproval() explicitly, so turning this on does not change when approvals
 * open, only where they are recorded. `handler` stays null: the push happens over workflow
 * (PushApprovedPeopleActivity, fired on ApprovalRequest::APPROVED), not a synchronous handler — People
 * has no state machine of its own to transition, unlike a Bill.
 */
class SeedPeopleApprovalPolicyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:approvals:seed-people-policy {apps_id} {company_id}';

    protected $description = 'Creates the approve_people approval policy for one company';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $systemModule = SystemModulesRepository::getByModelName(People::class, $app);

        $policy = ApprovalPolicy::firstOrCreate([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules_id' => $systemModule->getId(),
            'approval_type' => 'approve_people',
        ], [
            'steps' => [[
                'step' => 1,
                'resolver' => 'company_owner',
                'config' => [],
                'required_approvals' => 1,
            ]],
            'handler' => null,
            'trigger' => ApprovalTriggerEnum::MANUAL,
            'reject_policy' => 'any',
            'notify' => 'all',
            'allow_authority_override' => false,
            'expires_after_hours' => null,
        ]);

        $this->info(sprintf(
            'approve_people policy %s (id %d) for company %d.',
            $policy->wasRecentlyCreated ? 'created' : 'already existed',
            $policy->getId(),
            $company->getId(),
        ));

        return self::SUCCESS;
    }
}
