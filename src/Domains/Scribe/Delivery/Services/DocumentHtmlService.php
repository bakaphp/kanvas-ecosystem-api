<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Services;

use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfInvoicePdfService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\Services\QuoteSowPdfService;
use Kanvas\Social\Messages\Models\Message;

/**
 * What a document engagement's message carries for the customer page (`message.data`): the number, a
 * title, and the document as HTML. The HTML is the very string the PDF is printed from, so the page and
 * the PDF cannot drift: the quote or invoice layout, the SOW layout for a signature, and for an e-CF the
 * legal representation, which exists only once the authority accepted it (no `html` before that).
 */
final class DocumentHtmlService
{
    /**
     * @return array<string, string>
     */
    public function for(Invoice|Quote $document, DocumentActionEnum $action): array
    {
        $number = $document->documentNumber();
        $sow = $this->sow($document, $action);
        $title = $sow !== null ? 'Statement of Work' : new DocumentPdfService($document)->title();
        $html = $sow !== null ? new QuoteSowPdfService($document, $sow)->render() : $this->documentHtml($document);

        return [
            'document_number' => $number,
            'name' => $title . ' · ' . $number,
            ...($html === null ? [] : ['html' => $html]),
        ];
    }

    private function sow(Invoice|Quote $document, DocumentActionEnum $action): ?Message
    {
        return $action === DocumentActionEnum::SIGN_SOW && $document instanceof Quote ? $document->sowMessage : null;
    }

    private function documentHtml(Invoice|Quote $document): ?string
    {
        if ($document instanceof Quote || EcfCompliance::current($document) === null) {
            return new DocumentPdfService($document)->render();
        }

        return EcfCompliance::status($document)?->isAccepted() === true
            ? new EcfInvoicePdfService($document)->render()
            : null;
    }
}
