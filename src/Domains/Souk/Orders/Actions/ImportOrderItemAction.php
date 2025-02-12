<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Imports\OrderItemImport;
use Maatwebsite\Excel\Excel as ExcelExcel;

class ImportOrderItemAction
{
    public function __construct(
        protected Apps $app,
        protected ?array $request,
    ) {
    }

    public function execute()
    {
        $items = (new OrderItemImport($this->app))->toArray($this->request['input']['file'], null, ExcelExcel::CSV);
        return $items[0];
    }
}
