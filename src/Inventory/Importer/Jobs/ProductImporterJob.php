<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Jobs;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter as ImporterDto;
use Kanvas\Inventory\Regions\Models\Regions;

class ProductImporterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ImporterDto $importer,
        public Companies $company,
        public UserInterface $user,
        public Regions $region
    ) {
    }

    /**
     * handle.
     *
     * @return void
     */
    public function handle()
    {
        (new ProductImporterAction($this->importer, $this->company, $this->user, $this->region))->execute();
    }
}
