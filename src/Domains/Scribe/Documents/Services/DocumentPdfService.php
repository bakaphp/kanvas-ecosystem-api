<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Documents\Services;

use Illuminate\Support\Facades\View;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Documents\Enums\ConfigurationEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Models\QuoteLine;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Users\Models\Users;

/**
 * Renders an invoice or a quote as a printable document and attaches the PDF to it.
 *
 * Both documents print the same way — issuer block, bill-to block, lines, totals, notes — so they
 * share one layout instead of two that drift; the handful of fields that genuinely differ (title,
 * number column, due-vs-valid-until, amount paid) are resolved here and handed to the view flat.
 *
 * A tenant that wants its own branding sets the matching ConfigurationEnum key to the name of a
 * stored template; the packaged layout is the fallback, not the requirement.
 */
final class DocumentPdfService
{
    public const string DEFAULT_VIEW = 'pdf.scribe-document';
    public const string INVOICE_FIELD_NAME = 'invoice_pdf';
    public const string QUOTE_FIELD_NAME = 'quote_pdf';

    public function __construct(
        private readonly Invoice|Quote $document,
        private readonly ?string $templateName = null,
    ) {
    }

    public function generate(Users $user, ?string $fileName = null): Filesystem
    {
        $file = PdfService::htmlToPdf(
            $this->document->app,
            $user,
            $this->render(),
            $fileName ?? $this->fileName(),
        );

        $this->document->addFile($file, $this->fieldName());

        return $file;
    }

    public function render(): string
    {
        $data = $this->viewData();
        $templateName = $this->resolveTemplateName();

        if ($templateName !== null) {
            return new RenderTemplateAction($this->document->app, $this->document->company)
                ->execute($templateName, array_merge(['entity' => $this->document], $data));
        }

        return View::make(self::DEFAULT_VIEW, $data)->render();
    }

    public function fieldName(): string
    {
        return $this->isQuote() ? self::QUOTE_FIELD_NAME : self::INVOICE_FIELD_NAME;
    }

    public function fileName(): string
    {
        $kind = $this->isQuote() ? 'quote' : ($this->document->isCreditNote() ? 'credit-note' : 'invoice');
        $reference = $this->number() ?? ('draft-' . $this->document->getId());

        return $kind . '-' . (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $reference) . '.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $isQuote = $this->isQuote();

        return [
            'title' => $this->title(),
            'number' => $this->number(),
            'status' => $isQuote ? $this->document->status->value : $this->document->document_status->value,
            'currency' => $this->document->currency,
            'issuer' => $this->issuerBlock(),
            'customer' => $this->customerBlock(),
            'issued_date' => $this->document->issued_date?->toFormattedDateString(),
            'expiry_label' => $isQuote ? 'Valid until' : 'Due date',
            'expiry_date' => $isQuote
                ? $this->document->valid_until?->toFormattedDateString()
                : $this->document->due_date?->toFormattedDateString(),
            'lines' => $this->lines(),
            'totals' => $this->totals(),
            'notes' => $this->document->notes,
            'terms' => $this->document->terms,
        ];
    }

    private function isQuote(): bool
    {
        return $this->document instanceof Quote;
    }

    private function title(): string
    {
        if ($this->isQuote()) {
            return 'Quote';
        }

        return $this->document->isCreditNote() ? 'Credit Note' : 'Invoice';
    }

    private function number(): ?string
    {
        $number = $this->isQuote() ? $this->document->quote_number : $this->document->invoice_number;

        return trim((string) $number) !== '' ? (string) $number : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function issuerBlock(): array
    {
        $company = $this->document->company;

        return [
            'name' => (string) $company->name,
            'address_lines' => $this->addressLines(['address' => $company->address ?? null]),
            'email' => $company->email ?? null,
            'phone' => $company->phone ?? null,
            'website' => $company->website ?? null,
        ];
    }

    /**
     * Drafts have no billable snapshot — it freezes at send/issue time — so the live customer row
     * fills the block until then. Printing a draft with an empty bill-to is worse than printing a
     * name that can still change before the document is issued.
     *
     * @return array<string, mixed>
     */
    private function customerBlock(): array
    {
        $name = trim((string) $this->document->billable_display_name);
        $address = $this->document->billing_address_snapshot;

        if ($name === '' || $address === null) {
            /** @var Organization|null $customer */
            $customer = $this->document->customer;

            $name = $name !== '' ? $name : trim((string) $customer?->name);
            $address ??= $customer?->getBillingAddressArray();
        }

        return [
            'name' => $name !== '' ? $name : null,
            'legal_name' => $this->document->billable_legal_name,
            'tax_id' => $this->document->billable_tax_id,
            'email' => $this->document->billable_email,
            'address_lines' => $this->addressLines($address),
        ];
    }

    /**
     * @param array<array-key, mixed>|null $address
     *
     * @return list<string>
     */
    private function addressLines(?array $address): array
    {
        if ($address === null) {
            return [];
        }

        $cityLine = implode(', ', array_filter([
            trim((string) ($address['city'] ?? '')),
            trim(implode(' ', array_filter([
                trim((string) ($address['state'] ?? '')),
                trim((string) ($address['zip'] ?? '')),
            ]))),
        ]));

        return array_values(array_filter([
            trim((string) ($address['address'] ?? '')),
            trim((string) ($address['address_2'] ?? '')),
            $cityLine,
            trim((string) ($address['country'] ?? '')),
        ], static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(): array
    {
        return $this->document->lines->map(fn (InvoiceLine|QuoteLine $line): array => [
            'description' => (string) $line->description,
            'sku' => $line->sku,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price_native,
            'discount' => $line->discount_amount_native,
            'tax' => $line->tax_amount_native,
            'total' => $line->line_total_native,
        ])->values()->all();
    }

    /**
     * @return array<string, float|null>
     */
    private function totals(): array
    {
        return [
            'subtotal' => $this->document->subtotal_native,
            'discount' => $this->document->discount_native,
            'tax' => $this->document->tax_native,
            'total' => $this->document->total_native,
            'paid' => $this->isQuote() ? null : $this->document->paid_native,
            'balance_due' => $this->isQuote() ? null : $this->document->balance_due_native,
        ];
    }

    private function resolveTemplateName(): ?string
    {
        if ($this->templateName !== null && trim($this->templateName) !== '') {
            return trim($this->templateName);
        }

        $configured = $this->document->app->get(
            $this->isQuote()
                ? ConfigurationEnum::QUOTE_PDF_TEMPLATE->value
                : ConfigurationEnum::INVOICE_PDF_TEMPLATE->value
        );

        return $configured !== null && trim((string) $configured) !== '' ? trim((string) $configured) : null;
    }
}
