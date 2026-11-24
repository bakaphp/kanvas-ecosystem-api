<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Scribe\Delivery\Enums\DocumentSignalDispatchStatusEnum;
use Kanvas\Scribe\Delivery\Models\DocumentSignalDispatch;
use Kanvas\Scribe\Delivery\Services\DocumentEngagementSignalService;

class RetryDocumentSignalsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:retry-document-signals {--app_id= : One app; omitted = every app with a signal still to hand over}';

    protected $description = 'Open the AR agent task for document signals whose first attempt failed (no agent yet, or the task did not open).';

    public function handle(): int
    {
        $appIds = $this->option('app_id') !== null
            ? [(int) $this->option('app_id')]
            : DocumentSignalDispatch::query()
                ->whereIn('status', [
                    DocumentSignalDispatchStatusEnum::FAILED->value,
                    DocumentSignalDispatchStatusEnum::PENDING->value,
                ])
                ->distinct()
                ->pluck('apps_id')
                ->all();

        foreach ($appIds as $appId) {
            $app = Apps::find((int) $appId);
            if ($app === null) {
                continue;
            }

            $this->overwriteAppService($app);
            $dispatched = new DocumentEngagementSignalService()->retryDue($app);
            $this->info("App {$app->getId()}: {$dispatched} signals handed to the agent.");
        }

        return self::SUCCESS;
    }
}
