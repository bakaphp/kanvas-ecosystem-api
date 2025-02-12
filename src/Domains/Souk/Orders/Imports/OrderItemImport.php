<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Imports;

use Kanvas\Apps\Models\Apps;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\WithHeadingRow;


class OrderItemImport implements WithHeadingRow
{
    use Importable;

    public function __construct(
        protected Apps $app,
    ) {
    }
    
}
