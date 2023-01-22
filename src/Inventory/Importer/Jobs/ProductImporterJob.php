<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Importer\Jobs;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter as ImporterDto;
use Kanvas\Inventory\Regions\Models\Regions;

class ProductImporterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * constructor.
     *
     * @param array<int, ImporterDto> $importer
     * @param Companies $company
     * @param UserInterface $user
     * @param Regions $region
     */
    public function __construct(
        public array $importer,
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
        Auth::loginUsingId($this->user->getId());

        foreach ($this->importer as $request) {
            (new ProductImporterAction(
                ProductImporter::from($request),
                $this->company,
                $this->user,
                $this->region
            ))->execute();
        }
    }
}
