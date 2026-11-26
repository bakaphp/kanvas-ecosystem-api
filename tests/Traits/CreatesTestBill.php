<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Spatie\LaravelData\DataCollection;

/**
 * A draft USD vendor bill. Needs $this->kanvasApp and $this->company, as ScribeTestCase provides.
 */
trait CreatesTestBill
{
    /**
     * @param list<array{description: string, quantity: int|float, unit_price: int|float, sku?: string}> $lines
     */
    protected function createBillFor(Organization $vendor, array $lines): Bill
    {
        return new CreateBillAction(
            new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(
                    BillLineData::class,
                    array_map(
                        static fn (array $line): BillLineData => new BillLineData(
                            description: $line['description'],
                            quantity: $line['quantity'],
                            unit_price_native: $line['unit_price'],
                            sku: $line['sku'] ?? null,
                        ),
                        $lines
                    )
                ),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_number: 'INV-' . uniqid(),
                bill_date: Carbon::parse('2026-12-05'),
            ),
            static::$cachedUser,
        )->execute();
    }
}
