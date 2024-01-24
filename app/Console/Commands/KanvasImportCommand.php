<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Repositories\AppsRepository;
use Laravel\Scout\Console\ImportCommand;

class KanvasImportCommand extends ImportCommand
{
    protected $signature = 'kanvas:import
    {model : Class name of model to bulk import}
    {--app= : The application ID to import}
    {--c|chunk= : The number of records to import at a time (Defaults to configuration value: `scout.chunk.searchable`)}';

    public function handle(Dispatcher $events)
    {
        $appUuid = $this->option('app');
        $app = AppsRepository::findFirstByKey($appUuid);
        app()->scoped(Apps::class, function () use ($app) {
            return $app;
        });

        parent::handle($events);
    }
}
