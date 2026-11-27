<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Services;

use Baka\Support\Money;
use Baka\Support\Number;
use Baka\Support\Str;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Scribe\Documents\Services\DocumentIssuerBlock;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Users\Models\Users;

/**
 * Renders a purchase order as the document the vendor receives, and attaches the PDF to it.
 *
 * The layout is the `scribe-purchase-order` Twig row (company, then app, then platform), so a tenant restyles it
 * without code; the packaged file is the fallback.
 */
final class PurchaseOrderPdfService
{
    public const string TEMPLATE_NAME = 'scribe-purchase-order';
    public const string PACKAGED_TEMPLATE = 'views/pdf/scribe-purchase-order.twig';
    public const string FIELD_NAME = 'purchase_order_pdf';

    public function __construct(
        private readonly PurchaseOrder $order,
    ) {
    }

    public function generate(Users $user): Filesystem
    {
        $file = PdfService::htmlToPdf(
            app: $this->order->app,
            user: $user,
            html: $this->render(),
            fileName: $this->fileName(),
            company: $this->order->company,
        );

        $this->order->addFile($file, self::FIELD_NAME);

        return $file;
    }

    public function render(): string
    {
        return new RenderTemplateAction($this->order->app, $this->order->company)->renderNamedOrPackaged(
            self::TEMPLATE_NAME,
            ['entity' => $this->order] + $this->viewData(),
            self::PACKAGED_TEMPLATE
        );
    }

    public function fileName(): string
    {
        $number = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->order->order_number);

        return "purchase-order-{$number}.pdf";
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $vendor = $this->order->vendor;

        return [
            'number' => $this->order->order_number,
            'status' => $this->order->document_status?->value,
            'currency' => $this->order->currency,
            'order_date' => $this->order->order_date?->toFormattedDateString(),
            'expected_date' => $this->order->expected_date?->toFormattedDateString(),
            'issuer' => DocumentIssuerBlock::for($this->order->company),
            'vendor' => [
                'name' => (string) $vendor?->name,
                'address' => Str::trimToNull($vendor?->address),
            ],
            'lines' => $this->order->lines()
                ->orderBy('line_number')
                ->get()
                ->map(static fn ($line): array => [
                    'number' => $line->line_number,
                    'sku' => $line->sku,
                    'description' => $line->description,
                    'quantity' => Number::format((float) $line->order_qty, maxPrecision: 6),
                    'unit_cost' => Money::format((float) $line->unit_cost),
                    'total' => Money::format((float) $line->ext_cost),
                ])
                ->all(),
            'total' => Money::format((float) $this->order->order_total),
            'notes' => Str::trimToNull($this->order->notes),
        ];
    }
}
