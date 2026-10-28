<?php

declare(strict_types=1);

namespace App\Console\Commands\Onboarding;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Knowledge\Research\Workflows\IndexCompanyDocumentActivity;
use Kanvas\Intelligence\Knowledge\Workflows\PruneKnowledgeDocumentActivity;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Actions\WireRuleAction;

/**
 * Every workflow rule needs a default region per app, like any other: without one the attach-file
 * event is skipped silently.
 */
final class SetupCompanyKnowledgeIndexingRuleCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:onboarding:setup-company-knowledge-rule {app_id} {--company=0}';

    protected $description = 'Wire the workflow rules that turn onboarding uploads into company-wide knowledge: index on CompanyOnboarding attach-file, prune on Filesystem delete.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $companyId = (int) $this->option('company');

        $indexRule = new WireRuleAction(
            $app,
            $companyId,
            CompanyOnboarding::class,
            WorkflowEnum::ATTACH_FILE->value,
            IndexCompanyDocumentActivity::class,
            'Onboarding: index company documents',
            'Indexes documents uploaded during onboarding as the company-wide knowledge base.',
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
            $this->error(sprintf(WireRuleAction::MISSING_ACTION, IndexCompanyDocumentActivity::class));
        }
        if ($pruneRule === null) {
            $this->error(sprintf(WireRuleAction::MISSING_ACTION, PruneKnowledgeDocumentActivity::class));
        }
        if ($indexRule === null || $pruneRule === null) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Wired for app %d, company %d: CompanyOnboarding/attach-file rule #%d + Filesystem/deleted rule #%d.',
            $app->getId(),
            $companyId,
            $indexRule->getId(),
            $pruneRule->getId(),
        ));

        return self::SUCCESS;
    }
}
