<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Kanvas\Souk\Orders\Models\Order;

final readonly class OrderReceipt
{
    public const string CONFIG_KEY = 'pdf_receipt';

    public function __construct(
        public string $template,
        public string $filenamePattern = '{order_number}',
        public string $activityLog = 'ORDER_RECEIPT_GENERATED',
        public string $urlCustomField = 'receipt_url',
    ) {
    }

    public static function fromConfig(array $config): ?self
    {
        $template = trim((string) ($config['template'] ?? ''));

        if ($template === '') {
            return null;
        }

        return new self(
            template: $template,
            filenamePattern: (string) ($config['filename_pattern'] ?? '{order_number}'),
            activityLog: (string) ($config['activity_log'] ?? 'ORDER_RECEIPT_GENERATED'),
            urlCustomField: (string) ($config['url_custom_field'] ?? 'receipt_url'),
        );
    }

    public function toConfig(): array
    {
        return [
            'template' => $this->template,
            'filename_pattern' => $this->filenamePattern,
            'activity_log' => $this->activityLog,
            'url_custom_field' => $this->urlCustomField,
        ];
    }

    public function filenameFor(Order $order): string
    {
        $tokens = [];

        foreach ($order->metadata['data'] ?? [] as $key => $value) {
            if (is_scalar($value)) {
                $tokens['{' . $key . '}'] = (string) $value;
            }
        }

        $tokens['{order_number}'] = (string) $order->order_number;
        $tokens['{order_type}'] = (string) ($order->orderType?->name ?? '');

        return (string) preg_replace('/\{[^{}]*\}/', '', strtr($this->filenamePattern, $tokens));
    }
}
