<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Orders;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\ImportOrderItemAction;

class ImportOrderCsvMutation
{
    public function create(mixed $root, array $request): array
    {
        $app = app(Apps::class);


        $importOrderItems = new ImportOrderItemAction($app, $request);
        $items = $importOrderItems->execute();

        return [
            'items' => $items,
            'message' => 'Items imported successfully',
        ];
    }
}
