<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;

/** Appends a note to an already-pushed AR invoice or credit memo's Notes field in Acumatica. */
class PushInvoiceNoteToAcumaticaAction extends AbstractInvoiceAcumaticaDocumentAction
{
    /**
     * @return string the full note text now stored in Acumatica
     */
    public function execute(string $note): string
    {
        $invoiceGuid = $this->invoiceGuidOrFail('adding a note');

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
