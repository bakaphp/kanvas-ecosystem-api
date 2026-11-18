<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SyncRoadsideAssistanceStatusesAction;

class SetupRoadsideAssistanceCaseCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:movipass-setup-roadside-assistance {app_id?}';

    protected $description = 'Create or sync the Movipass roadside assistance order type, statuses and transitions (safe to re-run)';

    public function handle(): int
    {
        $appId = $this->argument('app_id');
        $app = $appId ? Apps::getById((int) $appId) : app(Apps::class);
        $this->overwriteAppService($app);

        $summary = new SyncRoadsideAssistanceStatusesAction($app)->execute();
        $duplicates = $summary['duplicate_statuses'];
        unset($summary['duplicate_statuses']);

        $this->table(['Change', 'Count'], array_map(
            fn (string $change, int $count) => [$change, $count],
            array_keys($summary),
            $summary,
        ));

        if ($duplicates !== []) {
            $this->warn('Duplicate active statuses found (merge by hand, orders reference them): ' . implode(', ', $duplicates));
        }

        $this->info('Movipass roadside assistance setup completed for app ' . $app->getId() . '.');

        return self::SUCCESS;
    }
}
