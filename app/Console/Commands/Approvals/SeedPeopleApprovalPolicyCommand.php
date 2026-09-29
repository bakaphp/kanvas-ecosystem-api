<?php

declare(strict_types=1);

namespace App\Console\Commands\Approvals;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Enums\PeopleSalesforceSyncApprovalTypeEnum;
use Kanvas\Guild\Customers\Enums\PeopleApprovalTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

/**
 * Seeds the three approval_type policies split across two independent Activities:
 * - `approve_people` — generic review of the People's own content in Kanvas
 *   (Guild\Customers\Activities\RequestPeopleContentApprovalActivity).
 * - `approve_people_salesforce_create` / `approve_people_salesforce_update` — gates the Salesforce
 *   push specifically (Connectors\Salesforce\Activities\RequestPeopleApprovalActivity opens whichever
 *   one applies; PushApprovedPeopleActivity's Rule listens on these two, not on `approve_people`).
 *
 * All three are independent — approving one is not a prerequisite for the others. Trigger is MANUAL
 * on purpose: the Activity calls requestApproval() explicitly, so turning this on does not change
 * when approvals open, only where they are recorded. `handler` stays null on all three: the push
 * happens over workflow (PushApprovedPeopleActivity, fired on ApprovalRequest::APPROVED), not a
 * synchronous handler — People has no state machine of its own to transition, unlike a Bill.
 */
class SeedPeopleApprovalPolicyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:approvals:seed-people-policy {apps_id} {company_id}';

    protected $description = 'Creates the People approval policies (content review + Salesforce sync) for one company';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('apps_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $systemModule = SystemModulesRepository::getByModelName(People::class, $app);

        $approvalTypes = [
            ...array_column(PeopleApprovalTypeEnum::cases(), 'value'),
            ...array_column(PeopleSalesforceSyncApprovalTypeEnum::cases(), 'value'),
        ];

        foreach ($approvalTypes as $approvalType) {
            $policy = ApprovalPolicy::firstOrCreate([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'system_modules_id' => $systemModule->getId(),
                'approval_type' => $approvalType,
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
                '%s policy %s (id %d) for company %d.',
                $approvalType,
                $policy->wasRecentlyCreated ? 'created' : 'already existed',
                $policy->getId(),
                $company->getId(),
            ));
        }

        return self::SUCCESS;
    }
}
