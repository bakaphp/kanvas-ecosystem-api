<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Actions;

use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Souk\Orders\Models\Order;

class StorePasoRapidoInvoicePdfAction
{
    public const string FILE_FIELD = 'paso_rapido_invoice';

    public function __construct(
        protected Order $order,
    ) {
    }

    public function execute(): ?string
    {
        $invoice = $this->order->metadata['data']['invoice'] ?? [];
        $sourceUrl = Str::trimToNull($invoice['pdf'] ?? null);

        if ($sourceUrl === null) {
            return null;
        }

        if (Str::trimToNull($invoice['pdf_url'] ?? null) !== null) {
            return $invoice['pdf_url'];
        }

        $content = SafeUrlFetcher::fetch($sourceUrl);

        if (! str_starts_with($content, '%PDF')) {
            throw new ValidationException('Paso Rapido invoice for order ' . $this->order->getId() . ' did not return a PDF');
        }

        $fileName = 'comprobante-' . (Str::trimToNull($invoice['invoice'] ?? null) ?? $this->order->getId()) . '.pdf';

        $filesystem = new FilesystemServices($this->order->app, $this->order->company)->createFileSystemFromBase64(
            base64_encode($content),
            $fileName,
            $this->order->user
        );

        $this->order->addFile($filesystem, self::FILE_FIELD);

        $this->order->refresh();
        $this->order->metadata = [
            ...$this->order->metadata ?? [],
            'data' => [
                ...$this->order->metadata['data'] ?? [],
                'invoice' => [
                    ...$this->order->metadata['data']['invoice'] ?? [],
                    'pdf_url' => $filesystem->url,
                ],
            ],
        ];
        $this->order->saveQuietly();

        return $filesystem->url;
    }
}
