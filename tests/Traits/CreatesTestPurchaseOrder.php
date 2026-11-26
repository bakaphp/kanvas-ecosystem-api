<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Purchasing\Actions\CreatePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrder as PurchaseOrderData;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;

/**
 * A draft USD purchase order to a vendor. Needs $this->kanvasApp and $this->company, as ScribeTestCase provides.
 */
trait CreatesTestPurchaseOrder
{
    protected function skipWithoutPdfBinary(): void
    {
        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }
    }

    /**
     * @param list<array<string, mixed>>|null $lines one item of 1 x 10 when left out
     */
    protected function createPurchaseOrderFor(Organization $vendor, ?array $lines = null, ?string $notes = null): PurchaseOrder
    {
        return new CreatePurchaseOrderAction(
            PurchaseOrderData::fromMultiple(
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                [
                    'vendor_id' => $vendor->getId(),
                    'currency' => 'USD',
                    'notes' => $notes,
                    'lines' => $lines ?? [['description' => 'Default item', 'quantity' => 1, 'unit_cost' => 10]],
                ]
            )
        )->execute();
    }
}
