<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents\Coding;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RequestPushApprovalAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\RequestSessionExtensionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Approvals\CodingExtensionApprovalHandler;
use Kanvas\Intelligence\AgentRuntime\Harness\Approvals\CodingPushApprovalHandler;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Throwable;

/**
 * Creates one of the coding runtime's approval policies:
 *
 * - `push` gates pushing a finished job's branch. Without it the branch is pushed straight away.
 * - `extension` turns a run that hits its time or cost limit into a question — approve for another
 *   block — instead of a stop. Without it the run stops at the limit. Approvers are notified directly
 *   (`notify: all`), because the job's asker is often nobody: agent-started work has none.
 *
 * A command rather than hand-written SQL, because a missing policy fails silently in both cases.
 */
class SetupCodingPushPolicyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:coding:setup-push-policy
        {--app= : App id}
        {--company= : Company id the policy applies to}
        {--type=push : push | extension}
        {--resolver=company_owner : Who may approve a push}
        {--approvals=1 : How many of them must approve}';

    protected $description = 'Create a coding-runtime approval policy (branch push, or more time/budget at the limit).';

    private const array TYPES = [
        'push' => [RequestPushApprovalAction::APPROVAL_TYPE, CodingPushApprovalHandler::class, 'none'],
        'extension' => [RequestSessionExtensionAction::APPROVAL_TYPE, CodingExtensionApprovalHandler::class, 'all'],
    ];

    public function handle(): int
    {
        $app = Apps::query()->where('id', (int) $this->option('app'))->first();
        $company = Companies::query()->where('id', (int) $this->option('company'))->first();

        if ($app === null || $company === null) {
            $this->error('Pass --app and --company with valid ids.');

            return self::FAILURE;
        }

        $type = (string) $this->option('type');

        if (! isset(self::TYPES[$type])) {
            $this->error('--type must be one of: ' . implode(', ', array_keys(self::TYPES)) . '.');

            return self::FAILURE;
        }

        [$approvalType, $handler, $notify] = self::TYPES[$type];

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
                'approval_type' => $approvalType,
            ],
            [
                'handler' => $handler,
                'steps' => [[
                    'step' => 1,
                    'resolver' => (string) $this->option('resolver'),
                    'required_approvals' => (int) $this->option('approvals'),
                    'config' => [],
                ]],
                'reject_policy' => 'any',
                'fallback_resolver' => 'company_owner',
                'fallback_config' => [],
                'notify' => $notify,
                'allow_authority_override' => 1,
            ]
        );

        $this->info(ucfirst($type) . ' approval policy #' . $policy->getId() . ' ready for company ' . $company->getId() . '.');
        $this->line('  handler:  ' . $handler);
        $this->line('  approver: ' . (string) $this->option('resolver')
            . ' (' . (int) $this->option('approvals') . ' required)');

        return self::SUCCESS;
    }
}
