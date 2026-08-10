<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Knowledge;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\Workflows\IndexKnowledgeDocumentActivity;
use Kanvas\Intelligence\Knowledge\Workflows\PruneKnowledgeDocumentActivity;
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

    protected $description = 'Wire the workflow rules that keep the company knowledge base in sync: index on Agent attach-file, prune on Filesystem delete.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $companyId = (int) $this->option('company');

        $indexRule = $this->wireRule(
            $app,
            $companyId,
            Agent::class,
            WorkflowEnum::ATTACH_FILE->value,
            IndexKnowledgeDocumentActivity::class,
            'Intelligence: index agent knowledge documents',
            'Indexes text/PDF files attached to an Agent as the company-wide knowledge base.',
        );

        $pruneRule = $this->wireRule(
            $app,
            $companyId,
            Filesystem::class,
            WorkflowEnum::DELETED->value,
            PruneKnowledgeDocumentActivity::class,
            'Intelligence: prune deleted file from knowledge',
            'Removes a deleted file\'s chunks from the company knowledge base.',
        );

        if ($indexRule === null || $pruneRule === null) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Wired for app %d, company %d: Agent/attach-file rule #%d + Filesystem/deleted rule #%d.',
            $app->getId(),
            $companyId,
            $indexRule->getId(),
            $pruneRule->getId(),
        ));

        return self::SUCCESS;
    }

    private function wireRule(
        Apps $app,
        int $companyId,
        string $entityClass,
        string $ruleTypeName,
        string $activityClass,
        string $name,
        string $description,
    ): ?Rule {
        $action = Action::where('model_name', $activityClass)->first();
        if (! $action) {
            $this->error("Action row not found for {$activityClass}. Run `php artisan kanvas:workflow-sync-actions` first.");

            return null;
        }

        $systemModule = SystemModulesRepository::getByModelName($entityClass, $app);
        $ruleType = RuleType::firstOrCreate(['name' => $ruleTypeName], ['is_deleted' => 0]);

        $rule = Rule::firstOrCreate(
            [
                'name' => $name,
                'rules_types_id' => $ruleType->getId(),
                'systems_modules_id' => $systemModule->getId(),
                'apps_id' => $app->getId(),
                'companies_id' => $companyId,
            ],
            [
                'description' => $description,
                // pattern '1' with no conditions evaluates truthy — the activity self-guards on entity/type.
                'pattern' => 1,
                'is_deleted' => 0,
            ]
        );

        $ruleWorkflowAction = RuleWorkflowAction::firstOrCreate(
            [
                'system_modules_id' => $systemModule->getId(),
                'actions_id' => $action->getId(),
            ],
            ['is_deleted' => 0]
        );

        RuleAction::firstOrCreate(
            [
                'rules_id' => $rule->getId(),
                'rules_workflow_actions_id' => $ruleWorkflowAction->getId(),
            ],
            ['weight' => 0, 'is_deleted' => 0]
        );

        return $rule;
    }
}
