<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr;

use Baka\Helpers\GenerateQrCode;
use Baka\Http\SafeUrlFetcher;
use Baka\Support\Money;
use Baka\Support\Str;
use finfo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Scribe\Documents\Services\DocumentPdfService;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Templates\Repositories\TemplatesRepository;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Our own legal printout of an accepted e-CF (invoice, credit or debit note): the document as
 * DocumentPdfService prints it, plus what DGII requires on the representation — e-NCF and its type,
 * sequence expiry, security code, signing date, the QR built from do_ecf.qr_url and the two legends under
 * it. Attached as `ecf_pdf`, the same field the provider's PDF uses, so whoever reads the file never cares
 * which one made it.
 *
 * The layout is the `scribe-ecf-invoice` Twig row (company, then app, then platform), falling back to the
 * packaged file, so a tenant can restyle it without code. The QR is rendered here and handed to the
 * template as a data URI: the sandbox has no functions to draw one.
 */
final class EcfInvoicePdfService
{
    // Its own field, beside the provider's `ecf_pdf`: both can exist, and delivery prefers this one.
    public const string TEMPLATE_NAME = 'scribe-ecf-invoice';
    public const string PACKAGED_TEMPLATE = 'views/pdf/scribe-ecf-invoice.twig';

    private const int QR_SIZE = 240;

    public function __construct(
        private readonly Invoice $invoice,
    ) {
    }

    public function generate(Users $user): Filesystem
    {
        $file = PdfService::htmlToPdf(
            app: $this->invoice->app,
            user: $user,
            html: $this->render(),
            fileName: 'e-cf-' . (EcfCompliance::read($this->invoice)['encf'] ?? $this->invoice->getId()) . '.pdf',
            company: $this->invoice->company,
        );

        $this->invoice->addFile($file, FiscalRegimeInterface::INVOICE_PDF_FIELD);

        return $file;
    }

    public function render(): string
    {
        $renderer = new RenderTemplateAction($this->invoice->app, $this->invoice->company);
        $context = $this->viewData();

        try {
            $template = TemplatesRepository::getByName(self::TEMPLATE_NAME, $this->invoice->app, $this->invoice->company);
        } catch (ModelNotFoundException) {
            return $renderer->execute(self::TEMPLATE_NAME, $context, File::get(resource_path(self::PACKAGED_TEMPLATE)));
        }

        return $renderer->renderTemplate($template, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $compliance = EcfCompliance::read($this->invoice);
        $type = EcfTypeEnum::tryFrom((string) ($compliance['type'] ?? ''));
        $document = new EcfDocumentBuilder()->build($this->invoice);
        $reference = $document->reference;

        $base = new DocumentPdfService($this->invoice)->viewData();

        return [
            ...$base,
            'issuer' => [...$base['issuer'], 'logo' => $this->logo()],
            'issued_date' => $this->invoice->issued_date?->format('d-m-Y'),
            'expiry_date' => $this->invoice->due_date?->format('d-m-Y'),
            'ecf' => [
                'encf' => (string) ($compliance['encf'] ?? ''),
                'type_code' => $type?->value,
                'type_label' => $type?->label() ?? $this->invoice->document_type->label(),
                'is_note' => $type?->isNote() ?? false,
                'sequence_expires_at' => $this->date($compliance['expires_at'] ?? null, 'd-m-Y'),
                'security_code' => $compliance['security_code'] ?? null,
                'signed_at' => $this->date($compliance['signed_at'] ?? null, 'd-m-Y H:i:s'),
                'qr_url' => $compliance['qr_url'] ?? null,
                'qr_image' => $this->qrImage($compliance['qr_url'] ?? null),
                'qr_legends' => array_values(array_filter((array) ($compliance['qr_legends'] ?? []), 'is_string')),
                'issuer_tax_id' => Str::trimToNull((string) $this->invoice->company->get(ConfigurationEnum::ECF_ISSUER_TAX_ID->value)),
                'payment_label' => $document->isCredit ? 'Venta a crédito' : 'Venta al contado',
                'exchange_rate' => $document->currency !== 'DOP' ? $document->exchangeRate : null,
                'purchase_order_number' => $document->purchaseOrderNumber,
                'export' => $document->export !== null ? [
                    'incoterm' => $document->export->incoterm,
                    'destination_country' => $document->export->destinationCountry,
                ] : null,
                'totals' => $document->totals->toArray(),
                'subtotal' => Money::stored(Money::sum($document->totals->taxed, $document->totals->exempt)),
                'internal_reference' => Str::trimmedStringOrNull($this->invoice->metadata['reference_number'] ?? null),
                'reference' => $reference !== null ? [
                    'encf' => $reference->encf,
                    'issue_date' => $this->date($reference->issueDate, 'd-m-Y'),
                    'modification_code' => $reference->modificationCode,
                    'modification_label' => $reference->modificationCode === EcfDocumentBuilder::MODIFICATION_CANCELS
                        ? 'Anula el NCF modificado'
                        : 'Corrige montos del NCF modificado',
                    'reason' => $reference->reason,
                ] : null,
            ],
        ];
    }

    /**
     * The company's own photo, embedded so the PDF engine never fetches a URL itself. Never the app's default
     * avatar: a placeholder on a fiscal document is worse than no logo.
     */
    private function logo(): ?string
    {
        $url = Str::trimmedStringOrNull($this->invoice->company->getFileByName('photo')?->filesystem?->url);

        if ($url === null) {
            return null;
        }

        try {
            $bytes = SafeUrlFetcher::fetch($url);
        } catch (Throwable) {
            return null;
        }

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->buffer($bytes);

        return str_starts_with($mime, 'image/') ? 'data:' . $mime . ';base64,' . base64_encode($bytes) : null;
    }

    private function qrImage(mixed $qrUrl): ?string
    {
        $qrUrl = Str::trimmedStringOrNull($qrUrl);

        if ($qrUrl === null) {
            return null;
        }

        try {
            return new GenerateQrCode($qrUrl, self::QR_SIZE)->asBase64();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function date(mixed $value, string $format): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format($format);
        } catch (Throwable) {
            return $value;
        }
    }
}
