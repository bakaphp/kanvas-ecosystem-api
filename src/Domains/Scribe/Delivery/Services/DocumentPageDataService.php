<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Services;

use Illuminate\Support\Arr;
use Kanvas\Scribe\Delivery\Support\QuoteSignatureRule;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Sow\DataTransferObject\StatementOfWork;

/**
 * What the public engagement page may show for a document: the printable view the PDF is rendered
 * from, the e-CF fields printed on the legal PDF, the PDF links and, for a quote, its SOW. Nothing else
 * of the row — no ids, internal notes, approval state or metadata — because the page is opened from a
 * link anyone holding it can read.
 */
class DocumentPageDataService
{
    /**
     * The e-CF keys DGII requires on the printed representation; the rest of do_ecf is provider state.
     */
    private const array PUBLIC_ECF_KEYS = ['encf', 'type', 'status', 'security_code', 'signed_at', 'qr_url', 'qr_legends', 'expires_at'];

    public function __construct(
        private readonly DocumentFilesService $files = new DocumentFilesService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function for(Invoice|Quote $document): array
    {
        $data = new DocumentPdfService($document)->viewData();
        $data['kind'] = $document->documentKind();
        $data['files'] = $this->files->publicUrls($document);

        return $document instanceof Invoice
            ? $this->withInvoiceFields($document, $data)
            : $this->withQuoteFields($document, $data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withInvoiceFields(Invoice $invoice, array $data): array
    {
        $ecf = Arr::only(EcfCompliance::read($invoice), self::PUBLIC_ECF_KEYS);
        $data['ecf'] = $ecf !== [] ? $ecf : null;
        $data['is_paid'] = $invoice->isPaid();

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withQuoteFields(Quote $quote, array $data): array
    {
        $sowMessage = $quote->sowMessage;
        $sow = $sowMessage !== null ? StatementOfWork::fromMessage($sowMessage)->toPayload($quote->getId()) : null;

        if ($sow !== null) {
            unset($sow['quote_id'], $sow['payment_terms_id'], $sow['template']);
        }

        $data['sow'] = $sow;
        $data['requires_signature'] = QuoteSignatureRule::requiresSignature($quote);
        $data['can_respond'] = $quote->status === QuoteStatusEnum::SENT;

        return $data;
    }
}
