<?php

declare(strict_types=1);

namespace Tests\Connectors\Support;

use Kanvas\Inventory\Variants\Models\Variants;

trait ConfiguresParkingRates
{
    private function setHourlyRate(Variants $variant, float $rate = 0.75): void
    {
        $variant->variantWarehouses()->each(function ($row) use ($rate): void {
            $row->config = ['rates' => ['hourly' => $rate]];
            $row->save();
        });
    }
}
