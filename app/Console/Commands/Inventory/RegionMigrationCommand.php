<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Illuminate\Console\Command;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Regions\Models\Regions as KanvasRegions;

class RegionMigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:region-migration';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Migrate inventory region to ecosystem';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $regions = Regions::withTrashed()->get();

        foreach ($regions as $region) {
            $newRegion = KanvasRegions::firstOrCreate([
                'id' => $region->getId(),
                'name' => $region->name,
                'companies_id' => $region->companies_id,
                'apps_id' => $region->apps_id,
            ], [
                'users_id' => $region->users_id,
                'currency_id' => $region->currency_id,
                'short_slug' => $region->short_slug,
                'settings' => $region->settings,
                'is_default' => $region->is_default,
            ]);
            $this->newLine();
            $this->info('Inventory Region ' . $region->getId() . ' to Kanvas Region ' . $newRegion->getId());
            $this->newLine();
        }

        $this->newLine();
        // $this->info('Inventory setup for Company ' . $company->name . ' completed successful');
        $this->newLine();

        return;
    }
}
