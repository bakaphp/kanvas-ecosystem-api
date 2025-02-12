<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Services\ProductsExportService;

class ExportProductsAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
    ) {
    }

    public function execute()
    {
        $export = new ProductsExportService($this->app);
        $path = 'products.csv';

        return $export->toCsv($path, 'public');
    }
}
