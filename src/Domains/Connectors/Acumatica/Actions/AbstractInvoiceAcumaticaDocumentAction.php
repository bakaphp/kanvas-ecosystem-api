<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Scribe\Invoices\Models\Invoice;

/** Shared plumbing for actions that mutate an already-pushed AR invoice/credit memo in Acumatica. */
abstract class AbstractInvoiceAcumaticaDocumentAction
{
    use HasAcumaticaWriter;

    protected Apps $app;

    public function __construct(
        protected Invoice $invoice,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $invoice->app;
        $this->writer = $writer;
    }

    /** The document must already have an Acumatica reference — resolves it or throws. */
    protected function invoiceGuidOrFail(string $action): string
    {
        $guid = (string) $this->invoice->get(CustomFieldEnum::INVOICE_ID->value, '');

        if ($guid === '') {
            throw new AcumaticaWriteException(
                "Invoice {$this->invoice->getId()} has no Acumatica reference — it must be pushed before {$action}."
            );
        }

        return $guid;
    }
}
