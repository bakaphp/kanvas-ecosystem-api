<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Exports\ProductsExport;

class ExportProductsAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
    ) {
    }

    public function execute()
    {
        $export = new ProductsExport($this->app);
        $path = 'products.csv';
    
        return $export->toCsv($path, 'public');
    }
}
