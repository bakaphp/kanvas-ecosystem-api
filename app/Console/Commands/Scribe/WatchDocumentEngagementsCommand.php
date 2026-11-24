<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Support\EngagementReferenceMap;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Delivery\Actions\WatchDocumentEngagementsAction;

class WatchDocumentEngagementsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:watch-document-engagements {--app_id= : One app; omitted = every app with shared documents}';

    protected $description = 'Hand the AR agent the sent quotes and invoices that went quiet (never opened, opened but unanswered).';

    public function handle(): int
    {
        $appIds = $this->option('app_id') !== null
            ? [(int) $this->option('app_id')]
            : Engagement::query()
                ->whereIn('entity_type', [EngagementReferenceMap::SCRIBE_INVOICE, EngagementReferenceMap::SCRIBE_QUOTE])
                ->distinct()
                ->pluck('apps_id')
                ->all();

        foreach ($appIds as $appId) {
            $app = Apps::find((int) $appId);
            if ($app === null) {
                continue;
            }

            $this->overwriteAppService($app);
            $raised = new WatchDocumentEngagementsAction($app)->execute();
            $this->info("App {$app->getId()}: {$raised} signals raised.");
        }

        return self::SUCCESS;
    }
}
