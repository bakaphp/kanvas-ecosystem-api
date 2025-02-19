<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Services;

use Kanvas\Apps\Models\Apps;
use League\Csv\Reader;

class OrderItemImportService
{
    public function __construct(
        protected Apps $app,
    ) {
    }

    public function getRecords($file): array
    {
        $csv = Reader::createFromPath($file->getRealPath());
        $csv->setHeaderOffset(0);
        $records = $csv->getRecords();
        $results = [];
        foreach ($records as $record) {
            $results[] = [
                'variant_id' => $record['Variant ID'],
                'quantity' => $record['Quantity'],
                'name' => $record['Name'],
                'price' => $record['Price'],
                'total' => 0,
                'discount' => $record['Discount'],
                'tax' => $record['Tax'],
                'tax_amount' => 0,
                'tax_percentage' => 0,
            ];
        }
        return $results;
    }
}
