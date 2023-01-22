<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter as ImporterDto;

class ProductImporterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * __construct.
     *
     * @param  string $source
     * @param  ImporterDto $importer
     * @param  Companies $company
     *
     * @return void
     */
    public function __construct(
        public string $source,
        public ImporterDto $importer,
        public Companies $company
    ) {
    }

    /**
     * handle.
     *
     * @return void
     */
    public function handle()
    {
        (new ProductImporterAction($this->source, $this->importer, $this->company))->execute();
    }
}
