<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentReceipts\Services;

use Baka\Support\AmountInWords;
use Baka\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Scribe\Documents\Services\DocumentIssuerBlock;
use Kanvas\Scribe\PaymentReceipts\Enums\ConfigurationEnum;
use Kanvas\Scribe\PaymentReceipts\Models\PaymentReceipt;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Users\Models\Users;

/**
 * Renders a payment receipt as a one-page Spanish document and attaches the PDF to it.
 *
 * The layout is the `scribe-payment-receipt` Twig row (company, then app, then platform), so a tenant restyles it
 * without code; the packaged file is the fallback.
 */
final class PaymentReceiptPdfService
{
    public const string TEMPLATE_NAME = 'scribe-payment-receipt';
    public const string PACKAGED_TEMPLATE = 'views/pdf/scribe-payment-receipt.twig';
    public const string FIELD_NAME = 'payment_receipt_pdf';

    private const array CURRENCY_LABELS = ['DOP' => 'RD$', 'USD' => 'US$'];

    public function __construct(private readonly PaymentReceipt $receipt)
    {
    }

    public function generate(Users $user): Filesystem
    {
        $file = PdfService::htmlToPdf(
            app: $this->receipt->app,
            user: $user,
            html: $this->render(),
            fileName: $this->fileName(),
            company: $this->receipt->company,
        );

        $this->receipt->addFile($file, self::FIELD_NAME);

        return $file;
    }

    public function render(): string
    {
        $renderer = new RenderTemplateAction($this->receipt->app, $this->receipt->company);

        return $renderer->renderNamedOrPackaged(
            self::TEMPLATE_NAME,
            array_merge(['entity' => $this->receipt], $this->viewData()),
            self::PACKAGED_TEMPLATE,
        );
    }

    public function fileName(): string
    {
        $number = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->receipt->receipt_number);

        return "recibo-de-pago-{$number}.pdf";
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $receipt = $this->receipt;

        return [
            'number' => $receipt->receipt_number,
            'issued_date' => $receipt->issued_at->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'customer_name' => $receipt->customer_name,
            'amount' => $this->currencyLabel($receipt->currency) . number_format($receipt->amount, 2),
            'amount_words' => AmountInWords::spanish($receipt->amount),
            'concept' => $this->concept(),
            'payment' => $this->paymentBreakdown(),
            'received_by' => $receipt->received_by,
            'signature' => $this->companyImage(ConfigurationEnum::SIGNATURE_FILE),
            'seal' => $this->companyImage(ConfigurationEnum::SEAL_FILE),
            'issuer' => DocumentIssuerBlock::for($receipt->company),
        ];
    }

    private function companyImage(ConfigurationEnum $file): ?string
    {
        return DocumentIssuerBlock::imageDataUri(
            $this->receipt->company->getFileByName($file->value)?->filesystem?->url
        );
    }

    private function currencyLabel(string $currency): string
    {
        return self::CURRENCY_LABELS[$currency] ?? $currency . ' ';
    }

    private function concept(): string
    {
        $numbers = array_map(
            static fn (array $invoice): string => $invoice['invoice_number'] ?? '#' . $invoice['invoice_id'],
            $this->receipt->invoices
        );

        return (count($numbers) === 1 ? 'Pago fact. No. ' : 'Pago facts. No. ') . implode(', ', $numbers);
    }

    /**
     * @return array{method: string, number: ?string, date: ?string, bank: ?string}
     */
    private function paymentBreakdown(): array
    {
        $payment = $this->receipt->payment;

        return [
            'method' => match ($payment->method) {
                PaymentMethodEnum::WIRE, PaymentMethodEnum::ACH => 'Transferencia',
                PaymentMethodEnum::CHECK => 'Cheque',
                PaymentMethodEnum::CASH => 'Efectivo',
                PaymentMethodEnum::CARD, PaymentMethodEnum::STRIPE => 'Tarjeta',
                default => 'Otro',
            },
            'number' => $this->receipt->instrument_number ?? Str::trimToNull($payment->reference),
            'date' => $this->receipt->instrument_date?->format('d/m/Y'),
            'bank' => $this->receipt->payer_bank,
        ];
    }
}
