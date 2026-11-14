<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Dhl;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Actions\SyncDhlTrackingAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Throwable;

class PollDhlTrackingCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:dhl-poll-tracking';

    protected $description = 'Poll DHL tracking for dispatched DHL orders, move them to delivered or returned and store the proof of delivery';

    public function handle(): int
    {
        $connections = IntegrationsCompany::query()
            ->whereHas('integration', fn ($query) => $query->where('name', IntegrationsEnum::DHL->value))
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->get()
            ->unique(fn (IntegrationsCompany $connection): string => $connection->apps_id . ':' . $connection->companies_id);

        foreach ($connections as $connection) {
            $app = Apps::getById((int) $connection->apps_id);
            $this->overwriteAppService($app);

            try {
                $summary = new SyncDhlTrackingAction($app, Companies::getById((int) $connection->companies_id))->execute();
                $this->info("App {$app->getId()} company {$connection->companies_id}: " . json_encode($summary));
            } catch (Throwable $exception) {
                report($exception);
                $this->error("App {$app->getId()} company {$connection->companies_id}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
