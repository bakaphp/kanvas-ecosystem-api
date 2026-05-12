<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Souk\Orders\Jobs\GenerateOrderReceiptPdfJob;
use Kanvas\Souk\Orders\Models\Order;

class DispatchOrderReceiptAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly array $overrides = [],
        protected readonly bool $sync = false,
    ) {
    }

    /**
     * Dispatch the PDF receipt job for the given order.
     *
     * Two paths:
     *  - Explicit override path: caller provides 'template' in $overrides (e.g. the impound
     *    activity). The `enabled` flag on OrderType config is bypassed so the receipt is always
     *    generated regardless of whether the seeder has run. Use $sync = true for callers that
     *    are already inside a queued job and need the file available immediately after dispatch.
     *  - Config-driven path: no 'template' override. Falls back to the OrderType pdf_receipt
     *    config; returns 'skipped' if config is missing or disabled. Always dispatched async.
     */
    public function execute(): array
    {
        $config = $this->order->orderType?->pdfReceiptConfig();
        $callerSuppliedTemplate = isset($this->overrides['template']);

        if (! $callerSuppliedTemplate && (empty($config) || ($config['enabled'] ?? false) !== true)) {
            return [
                'status' => 'skipped',
                'reason' => 'pdf_receipt config disabled or missing',
            ];
        }

        $template = $this->overrides['template'] ?? $config['template'] ?? '';
        $filenamePattern = $this->overrides['filename_pattern'] ?? $config['filename_pattern'] ?? '{order_number}';
        $activityLog = $this->overrides['activity_log'] ?? $config['activity_log'] ?? null;

        $filename = $this->resolveFilenameTokens($filenamePattern);

        if ($this->sync) {
            GenerateOrderReceiptPdfJob::dispatchSync(
                $this->order,
                $this->order->user,
                $template,
                $filename,
                [],
                $activityLog,
            );
        } else {
            GenerateOrderReceiptPdfJob::dispatch(
                $this->order,
                $this->order->user,
                $template,
                $filename,
                [],
                $activityLog,
            );
        }

        return [
            'status' => 'processing',
            'file_name' => "{$filename}.pdf",
            'message' => 'PDF generation started. You will receive an email with the download link when ready.',
        ];
    }

    private function resolveFilenameTokens(string $pattern): string
    {
        $tokens = [
            '{order_number}' => (string) ($this->order->order_number ?? ''),
            '{order_uuid}' => (string) ($this->order->uuid ?? ''),
        ];

        $metaData = $this->order->metadata['data'] ?? [];
        foreach ($metaData as $key => $value) {
            if (is_scalar($value)) {
                $tokens['{' . $key . '}'] = $this->sanitizeFilenameToken((string) $value);
            }
        }

        return str_replace(array_keys($tokens), array_values($tokens), $pattern);
    }

    /**
     * Strip path traversal sequences, control characters, and non-safe characters from a
     * user-controlled token before it lands in a filename.
     */
    private function sanitizeFilenameToken(string $value): string
    {
        // Strip null bytes and ASCII control characters
        $value = preg_replace('/[\x00-\x1f\x7f]/', '', $value) ?? '';

        // Remove path traversal sequences and directory separators
        $value = str_replace(['..', '/', '\\'], '', $value);

        // Replace anything that is not alphanumeric, dash, underscore, or dot with underscore
        $value = preg_replace('/[^a-zA-Z0-9\-_.]/', '_', $value) ?? '';

        // Collapse consecutive underscores that may result from the above replacements
        $value = preg_replace('/_+/', '_', $value) ?? '';

        $value = trim($value, '_');

        return substr($value, 0, 100);
    }
}
