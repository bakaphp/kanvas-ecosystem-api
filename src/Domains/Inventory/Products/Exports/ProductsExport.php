<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Exports;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use League\Csv\Writer;

class ProductsExport
{
    public function __construct(
        protected Apps $app,
    ) {
    }

       /**
     * @return array<string>
     */
    public function headings(): array
    {
        return [
            'Variant ID',
            'Name',
            'SKU',
            'Quantity',
            'Price',
            'Tax',
            'Discount',
            'Currency'
        ];
    }

    public function map($variant): array
    {
        return [
            $variant->id,
            $variant->name,
            (string) ($variant->sku ?? $variant->id),
            (int) $variant->quantity,
            (float) $variant->price,
            (float) ($variant->tax ?? 0),
            (float) ($variant->discount ?? 0),
            'USD'
        ];
    }

    public function collection(): Collection {
        $products = Products::where([
            'apps_id' => $this->app->getId(),
        ])->with(['variants'])->get();

        return $products->flatMap(function ($product) {
            return $product->variants;
        });
    }

    public function toCsv() {
        $header = $this->headings();
        $records = $this->collection();
        $csv = Writer::createFromString();

        //insert the header
        $csv->insertOne($header);
        //insert all the records
        $csv->insertAll($records->map(fn ($variant) => $this->map($variant))->toArray());

        return $csv->toString(); 
    }
}
