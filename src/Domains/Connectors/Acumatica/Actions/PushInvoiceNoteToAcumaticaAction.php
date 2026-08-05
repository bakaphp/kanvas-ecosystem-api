<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Scribe\Invoices\Models\Invoice;

/** Appends a note to an already-pushed AR invoice or credit memo's Notes field in Acumatica. */
class PushInvoiceNoteToAcumaticaAction
{
    use HasAcumaticaWriter;

    /** The invoice's own app — the tenant whose Acumatica config/credentials this push runs against. */
    protected Apps $app;

    public function __construct(
        protected Invoice $invoice,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $invoice->app;
        $this->writer = $writer;
    }

    /**
     * @return string the full note text now stored in Acumatica
     */
    public function execute(string $note): string
    {
        $invoiceGuid = (string) $this->invoice->get(CustomFieldEnum::INVOICE_ID->value, '');

        if ($invoiceGuid === '') {
            throw new AcumaticaWriteException(
                "Invoice {$this->invoice->getId()} has no Acumatica reference — it must be pushed before adding a note."
            );
        }

        return $this->writer()->withSession(
            function (Client $client) use ($invoiceGuid, $note): string {
                $existing = (string) AcumaticaPayload::value($client->get('Invoice/' . $invoiceGuid), 'note', '');
                $combined = $existing !== '' ? $existing . "\n" . $note : $note;

                $client->put('Invoice', ['id' => $invoiceGuid, 'note' => ['value' => $combined]]);

                return $combined;
            }
        );
    }
}
