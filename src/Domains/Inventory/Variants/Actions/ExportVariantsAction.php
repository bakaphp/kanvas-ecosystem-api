<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Apps\Models\Apps;
use Illuminate\Support\Facades\Storage;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Exports\VariantsExport;
class ExportVariantsAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
    ) {
    }

    public function execute()
    {
        $export = new VariantsExport($this->app);
        $path = 'variants.csv';
    
        $export->store(
            $path,
            'public',
            \Maatwebsite\Excel\Excel::CSV
        );
        
        return Storage::disk('public')->url($path);
    }
}
