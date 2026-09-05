<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Observers;

use Kanvas\Scribe\Invoices\Models\Invoice;

class InvoiceObserver
{
    public function updating(Invoice $invoice): void
    {
        $invoice->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
