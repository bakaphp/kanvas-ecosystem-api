<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Scribe\Invoices\Models\Invoice;

/** Attaches a file to an already-pushed AR invoice or credit memo in Acumatica via its own files:put link. */
class AttachFileToAcumaticaInvoiceAction extends AbstractInvoiceAcumaticaDocumentAction
{
    public function __construct(
        Invoice $invoice,
        protected string $fileUrl,
        protected string $fileName,
        ?AcumaticaWriteService $writer = null,
    ) {
        parent::__construct($invoice, $writer);
    }

    public function execute(): void
    {
        $invoiceGuid = $this->invoiceGuidOrFail('attaching a file');

        $this->writer()->withSession(function (Client $client) use ($invoiceGuid): void {
            $template = AcumaticaPayload::filesPutHref($client->get('Invoice/' . $invoiceGuid));

            if ($template === null) {
                throw new AcumaticaWriteException(
                    "Invoice {$this->invoice->getId()} has no files:put link in Acumatica — cannot attach a file."
                );
            }

            $bytes = SafeUrlFetcher::fetch($this->fileUrl);
            $url = str_ireplace('{filename}', rawurlencode($this->fileName), $template);

            $client->putFile($url, $bytes, $this->contentType());
        });
    }

    private function contentType(): string
    {
        return match (strtolower(pathinfo($this->fileName, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }
}
