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
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Actions\WireRuleAction;

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

        $indexRule = new WireRuleAction(
            $app,
            $companyId,
            Agent::class,
            WorkflowEnum::ATTACH_FILE->value,
            IndexKnowledgeDocumentActivity::class,
            'Intelligence: index agent knowledge documents',
            'Indexes text/PDF files attached to an Agent as the company-wide knowledge base.',
        )->execute();

        $pruneRule = new WireRuleAction(
            $app,
            $companyId,
            Filesystem::class,
            WorkflowEnum::DELETED->value,
            PruneKnowledgeDocumentActivity::class,
            'Intelligence: prune deleted file from knowledge',
            'Removes a deleted file\'s chunks from the company knowledge base.',
        )->execute();

        if ($indexRule === null) {
            $this->error(sprintf(WireRuleAction::MISSING_ACTION, IndexKnowledgeDocumentActivity::class));
        }
        if ($pruneRule === null) {
            $this->error(sprintf(WireRuleAction::MISSING_ACTION, PruneKnowledgeDocumentActivity::class));
        }
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
}
