<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Imports\OrderItemImport;

class ImportOrderItemAction
{
    public function __construct(
        protected Apps $app,
        protected ?array $request,
    ) {
    }

    public function execute()
    {
        $items = (new OrderItemImport($this->app))->getRecords($this->request['input']['file']);
        return $items;
    }
}
