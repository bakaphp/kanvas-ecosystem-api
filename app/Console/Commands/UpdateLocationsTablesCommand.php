<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Locations\Actions\UpdateAllLocationsAction;

class UpdateLocationsTablesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:update-locations-tables';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Command description';

    public function handle(): void
    {
        $updateLocations = new UpdateAllLocationsAction();
        $updateLocations->execute();

        $this->info('Tables Cities, Countries, Locales and States updated successfully.');
    }
}
