<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Enums\CorporateApplicationApprovalModeEnum as ApprovalMode;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\AutoApproveCorporateLeadActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\PublishApprovedParkingActivity;
use Kanvas\Connectors\Movipass\Workflows\Activities\SetupApprovedCorporateCompanyActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

class SetupParkingApplicationRulesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:movipass-setup-parking-application-rules {app_id} {--receiver= : LeadReceiver id that takes parking applications}';

    protected $description = 'Wire the workflow rules (and optionally the receiver) for parking applications on an app';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $leadModule = SystemModulesRepository::getByModelName(Lead::class, $app);

        $this->wireRule(
            $app,
            $leadModule->getId(),
            WorkflowEnum::CREATED->value,
            'Corporate applications: triage on lead created',
            [AutoApproveCorporateLeadActivity::class],
        );

        $this->wireRule(
            $app,
            $leadModule->getId(),
            WorkflowEnum::CORPORATE_APPLICATION_APPROVED->value,
            'Corporate applications: provision company and publish parking on approval',
            [SetupApprovedCorporateCompanyActivity::class, PublishApprovedParkingActivity::class],
        );

        if ($this->option('receiver')) {
            $this->wireReceiver($app, (int) $this->option('receiver'));
        }

        return self::SUCCESS;
    }

    private function wireRule(
        Apps $app,
        int $systemModuleId,
        string $event,
        string $name,
        array $activities
    ): void {
        $ruleType = RuleType::firstOrCreate(['name' => $event], ['is_deleted' => 0]);

        $rule = Rule::firstOrCreate(
            [
                'name' => $name,
                'rules_types_id' => $ruleType->getId(),
                'systems_modules_id' => $systemModuleId,
                'apps_id' => $app->getId(),
                'companies_id' => 0,
            ],
            [
                'description' => 'Wired by kanvas:movipass-setup-parking-application-rules',
                'pattern' => 1,
                'params' => [],
                'is_deleted' => 0,
            ]
        );

        foreach ($activities as $weight => $activity) {
            $action = Action::where('model_name', $activity)->first();

            if ($action === null) {
                $this->error(class_basename($activity) . ' has no action row; run `php artisan kanvas:workflow-sync-actions` first');

                continue;
            }

            $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate(
                ['system_modules_id' => $systemModuleId, 'actions_id' => $action->getId()],
                ['is_deleted' => 0]
            );

            RuleAction::firstOrCreate(
                ['rules_id' => $rule->getId(), 'rules_workflow_actions_id' => $ruleWorkflowAction->getId()],
                ['weight' => $weight, 'is_deleted' => 0]
            );
        }

        $this->info("Rule #{$rule->getId()} on Lead/{$event}: " . implode(', ', array_map('class_basename', $activities)));
    }

    private function wireReceiver(Apps $app, int $receiverId): void
    {
        $receiver = LeadReceiver::getById($receiverId, $app);

        if (empty($receiver->get(ApprovalMode::RECEIVER_KEY))) {
            $receiver->set(ApprovalMode::RECEIVER_KEY, ApprovalMode::MANUAL->value);
        }

        $app->set(ConfigurationEnum::PARKING_RECEIVER_ID->value, $receiver->getId());

        $this->info("Receiver #{$receiver->getId()} ({$receiver->name}) takes parking applications, approval_mode=" . $receiver->get(ApprovalMode::RECEIVER_KEY));
    }
}
