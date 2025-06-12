<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;

class ScoutCleanLegacyInventoryCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:scout-clean-legacy-inventory {app_id} {company_ids*}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Clean up scout index for legacy inventory products';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyIds = $this->argument('company_ids');

        // Process each company individually to avoid index issues
        foreach ($companyIds as $companyId) {
            $this->info("Processing company ID: {$companyId}");

            Products::fromApp($app)
                ->where('companies_id', $companyId)
                ->where('is_published', 0)
                ->unsearchable();

            $this->info("Completed cleaning scout index for company: {$companyId}");
        }

        $this->info('Successfully cleaned scout index for all companies: ' . implode(', ', $companyIds));

        return;
    }
}
