<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RequestPushApprovalAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Approvals\CodingPushApprovalHandler;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Throwable;

/**
 * Creates the approval policy that gates pushing a coding job's branch.
 *
 * Without it the harness has no gate: `RequestPushApprovalAction` finds no policy and returns null, so
 * a finished job simply sits there. That is the safe direction, but it is silent — which is why this
 * exists as a command rather than as hand-written SQL nobody remembers to run.
 */
class SetupCodingPushPolicyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:coding:setup-push-policy
        {--app= : App id}
        {--company= : Company id the policy applies to}
        {--resolver=company_owner : Who may approve a push}
        {--approvals=1 : How many of them must approve}';

    protected $description = 'Create the approval policy that gates pushing a coding agent\'s branch.';

    public function handle(): int
    {
        $app = Apps::query()->where('id', (int) $this->option('app'))->first();
        $company = Companies::query()->where('id', (int) $this->option('company'))->first();

        if ($app === null || $company === null) {
            $this->error('Pass --app and --company with valid ids.');

            return self::FAILURE;
        }

        $this->overwriteAppService($app);

        try {
            $systemModuleId = SystemModulesRepository::getByModelName(Task::class, $app)->getId();
        } catch (Throwable $e) {
            $this->error(
                'No system_modules row for Task on app ' . $app->getId() . '. Run '
                . 'kanvas:create-global-system-modules-from-template --app_id=' . $app->getId() . ' first.'
            );

            return self::FAILURE;
        }

        $policy = ApprovalPolicy::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'system_modules_id' => $systemModuleId,
                'trigger' => ApprovalTriggerEnum::MANUAL->value,
                'approval_type' => RequestPushApprovalAction::APPROVAL_TYPE,
            ],
            [
                'handler' => CodingPushApprovalHandler::class,
                'steps' => [[
                    'step' => 1,
                    'resolver' => (string) $this->option('resolver'),
                    'required_approvals' => (int) $this->option('approvals'),
                    'config' => [],
                ]],
                'reject_policy' => 'any',
                'fallback_resolver' => 'company_owner',
                'fallback_config' => [],
                'notify' => 'none',
                'allow_authority_override' => 1,
            ]
        );

        $this->info('Push approval policy #' . $policy->getId() . ' ready for company ' . $company->getId() . '.');
        $this->line('  handler:  ' . CodingPushApprovalHandler::class);
        $this->line('  approver: ' . (string) $this->option('resolver')
            . ' (' . (int) $this->option('approvals') . ' required)');
        $this->line('  A finished coding job with a branch will now ask before anything is pushed.');

        return self::SUCCESS;
    }
}
