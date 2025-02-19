<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Services\OrderItemImportService;

class ImportOrderItemAction
{
    public function __construct(
        protected Apps $app,
        protected ?array $request,
    ) {
    }

    public function execute()
    {
        $items = (new OrderItemImportService($this->app))->getRecords($this->request['input']['file']);
        return $items;
    }
}
