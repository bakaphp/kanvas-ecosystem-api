<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Exports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Variants\Models\Variants;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class VariantsExport implements FromCollection, WithMapping, WithHeadings
{
    use Exportable;

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

    /**
     * @param Variants $variants
     */
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
        return Variants::where([
            'apps_id' => $this->app->getId(),
        ])->get();
    }
}
