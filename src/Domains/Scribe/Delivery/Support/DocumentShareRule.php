<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\Support;

use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * When a document may be put in a customer's hands, by send or by a link someone shares themselves.
 * An e-CF company's invoice only once DGII accepted it: before that the number is not yet a legal
 * document, and a rejection would void what the customer already holds.
 */
final class DocumentShareRule
{
    public static function assertInvoiceShareable(Invoice $invoice, EcfIssuanceService $ecf = new EcfIssuanceService()): void
    {
        $status = $invoice->document_status;

        if (! in_array($status, [InvoiceDocumentStatusEnum::ISSUED, InvoiceDocumentStatusEnum::SENT], true)) {
            throw new DocumentDeliveryException(
                DocumentDeliveryException::INVALID_STATUS,
                "Invoice {$invoice->getId()} is {$status->value}; only an issued invoice can be sent."
            );
        }

        if (! $ecf->issuesEcf($invoice)) {
            return;
        }

        $ecfStatus = EcfCompliance::status($invoice);
        if ($ecfStatus === null || ! $ecfStatus->isAccepted()) {
            throw new DocumentDeliveryException(
                DocumentDeliveryException::ECF_NOT_ACCEPTED,
                "Invoice {$invoice->invoice_number} is not authorized by DGII yet (e-CF status: "
                . ($ecfStatus?->value ?? 'none') . '). It goes out once the e-CF is accepted.'
            );
        }
    }

    /**
     * A link never changes a document's status, so a draft quote (no number, no frozen customer
     * snapshot) is sent first; send_quote is what numbers it.
     */
    public static function assertLinkable(Invoice|Quote $document): void
    {
        if ($document instanceof Invoice) {
            self::assertInvoiceShareable($document);

            return;
        }

        if ($document->status === QuoteStatusEnum::DRAFT) {
            throw new DocumentDeliveryException(
                DocumentDeliveryException::INVALID_STATUS,
                "Quote {$document->getId()} is still a draft with no number. Send it with send_quote first; "
                . 'that numbers it and marks it sent.'
            );
        }
    }
}
