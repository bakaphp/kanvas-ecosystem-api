<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Locations\Actions\UpdateAllLocationsAction;
use Kanvas\Locations\Actions\UpdateCitiesAction;
use Kanvas\Locations\Actions\UpdateCountriesAction;
use Kanvas\Locations\Actions\UpdateStatesAction;

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
        $countries = new UpdateCountriesAction();
        $countries->execute();

        $states = new UpdateStatesAction();
        $states->execute();

        $cities = new UpdateCitiesAction();
        $cities->execute();

        $this->info('Tables Cities, Countries and States updated successfully.');
    }
}
