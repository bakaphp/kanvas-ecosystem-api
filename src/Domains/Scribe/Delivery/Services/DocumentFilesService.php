<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Services;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\Services\QuoteSowPdfService;
use Kanvas\Users\Models\Users;

/**
 * Which attached PDF a customer receives for a document. An invoice gets its legal PDF — our own e-CF
 * render when one exists, else the provider's, else the plain invoice PDF — and a quote its quote PDF
 * plus the SOW when it has one. A document with nothing attached yet gets its plain PDF rendered, so a
 * send never goes out without the document.
 */
class DocumentFilesService
{
    /**
     * Most authoritative first. `ecf_invoice_pdf` is the field our own legal e-CF render (plan 2b.3)
     * attaches; the provider's PDF (WebPOS) is the v1 legal PDF (decision 7).
     */
    public const array INVOICE_LEGAL_PDF_FIELDS = [
        FiscalRegimeInterface::INVOICE_PDF_FIELD,
        ApplyFiscalAnswerAction::PDF_FIELD_NAME,
        DocumentPdfService::INVOICE_FIELD_NAME,
    ];

    public function legalInvoicePdf(Invoice $invoice): ?Filesystem
    {
        return $this->ecfPdf($invoice) ?? $this->attached($invoice, DocumentPdfService::INVOICE_FIELD_NAME);
    }

    /**
     * The e-CF PDF carrying DGII's stamp and QR, never the plain invoice render.
     */
    public function ecfPdf(Invoice $invoice): ?Filesystem
    {
        foreach (array_diff(self::INVOICE_LEGAL_PDF_FIELDS, [DocumentPdfService::INVOICE_FIELD_NAME]) as $field) {
            $file = $this->attached($invoice, $field);
            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    public function quotePdf(Quote $quote): ?Filesystem
    {
        return $this->attached($quote, DocumentPdfService::QUOTE_FIELD_NAME);
    }

    public function sowPdf(Quote $quote): ?Filesystem
    {
        return $this->attached($quote, QuoteSowPdfService::FIELD_NAME);
    }

    /**
     * @return list<Filesystem>
     */
    public function forDelivery(Invoice|Quote $document, Users $user): array
    {
        if ($document instanceof Invoice) {
            return [$this->legalInvoicePdf($document) ?? new DocumentPdfService($document)->generate($user)];
        }

        return array_values(array_filter([
            $this->quotePdf($document) ?? new DocumentPdfService($document)->generate($user),
            $this->sowPdf($document),
        ]));
    }

    /**
     * @return array<string, string>
     */
    public function publicUrls(Invoice|Quote $document): array
    {
        $files = $document instanceof Invoice
            ? ['legal_pdf' => $this->legalInvoicePdf($document)]
            : ['quote_pdf' => $this->quotePdf($document), 'sow_pdf' => $this->sowPdf($document)];

        return array_filter(array_map(
            static fn (?Filesystem $file): ?string => $file?->url,
            $files,
        ));
    }

    private function attached(Invoice|Quote $document, string $field): ?Filesystem
    {
        return $document->getFileByName($field)?->filesystem;
    }
}
