<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Knowledge;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\Workflows\IndexKnowledgeDocumentActivity;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;

class SetupAgentKnowledgeIndexingRuleCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intelligence:setup-agent-knowledge-rule {app_id} {--company=0}';

    protected $description = 'Wire the attach-file workflow rule that indexes an Agent\'s documents as company-wide knowledge.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $action = Action::where('model_name', IndexKnowledgeDocumentActivity::class)->first();
        if (! $action) {
            $this->error('Action row not found. Run `php artisan kanvas:workflow-sync-actions` first to register the activity.');

            return self::FAILURE;
        }

        $systemModule = SystemModulesRepository::getByModelName(Agent::class, $app);
        $ruleType = RuleType::firstOrCreate(
            ['name' => WorkflowEnum::ATTACH_FILE->value],
            ['is_deleted' => 0],
        );
        $companyId = (int) $this->option('company');

        $rule = Rule::firstOrCreate(
            [
                'name' => 'Intelligence: index agent knowledge documents',
                'rules_types_id' => $ruleType->getId(),
                'systems_modules_id' => $systemModule->getId(),
                'apps_id' => $app->getId(),
                'companies_id' => $companyId,
            ],
            [
                'description' => 'Indexes text/PDF files attached to an Agent as the company-wide knowledge base.',
                // pattern '1' with no conditions evaluates truthy — the activity self-guards on entity + staff upload.
                'pattern' => 1,
                'is_deleted' => 0,
            ]
        );

        $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate(
            [
                'system_modules_id' => $systemModule->getId(),
                'actions_id' => $action->getId(),
            ],
            [
                'is_deleted' => 0,
            ]
        );

        RuleAction::firstOrCreate(
            [
                'rules_id' => $rule->getId(),
                'rules_workflow_actions_id' => $ruleWorkflowAction->getId(),
            ],
            [
                'weight' => 0,
                'is_deleted' => 0,
            ]
        );

        $this->info(sprintf(
            'Rule wired: #%d (%s) on Agent/attach-file for app %d, company %d.',
            $rule->getId(),
            $rule->name,
            $app->getId(),
            $companyId
        ));

        return self::SUCCESS;
    }
}
