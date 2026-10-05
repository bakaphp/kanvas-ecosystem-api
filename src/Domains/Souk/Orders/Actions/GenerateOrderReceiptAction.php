<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\DataTransferObject\OrderReceipt;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

class GenerateOrderReceiptAction
{
    public function __construct(
        protected Order $order,
        protected UserInterface $user,
        protected ?OrderReceipt $receipt = null,
    ) {
    }

    public function execute(): Filesystem
    {
        $receipt = $this->receipt ?? $this->order->orderType?->pdfReceipt();

        if ($receipt === null) {
            throw new ValidationException(
                'Order type ' . ($this->order->orderType?->name ?? 'unknown') . ' has no PDF receipt template configured'
            );
        }

        $filename = $receipt->filenameFor($this->order);

        $pdfFile = PdfService::generatePdfFromTemplate(
            $this->order->app,
            $this->user,
            $receipt->template,
            $this->order,
            $this->receiptData(),
        );

        $this->order->addFile($pdfFile, $filename);
        $this->order->set($receipt->urlCustomField, $pdfFile->url);

        activity()
            ->causedBy($this->user)
            ->performedOn($this->order)
            ->withProperties([
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'user_id' => $this->user->id,
                'timestamp' => now(),
                'file_id' => $pdfFile->id,
                'file_url' => $pdfFile->url,
                'file_path' => $pdfFile->path,
            ])
            ->log($receipt->activityLog);

        return $pdfFile;
    }

    public function receiptData(): array
    {
        $payment = $this->order->payments
            ->whereIn('status', [PaymentStatusEnum::PAID->value])
            ->first();

        $paymentMethod = $this->resolvePaymentMethod($payment);
        $metadataCardBrand = $payment?->metadata['enrollment_data']['paymentInformation']['card']['type'] ?? null;

        $paymentMethodName = match (true) {
            filled($payment?->payment_method_brand) => trim(
                $payment->payment_method_brand . ' ' . ($payment->payment_method_last_four ?? '')
            ),
            filled($paymentMethod?->payment_methods_brand) => trim(
                $paymentMethod->payment_methods_brand . ' ' . ($paymentMethod->payment_ending_numbers ?? '')
            ),
            filled($metadataCardBrand) => strtoupper((string) $metadataCardBrand),
            filled($payment?->payment_method) => ucfirst((string) $payment->payment_method),
            default => null,
        };

        return [
            'paymentDate' => $this->order->metadata['data']['payment_date'] ?? '',
            'releaseDate' => $this->order->metadata['data']['release_date'] ?? null,
            'paymentType' => $paymentMethodName ?? 'Transferencia/Depósito',
            'transactionNumber' => $payment?->metadata['data']['payment_response']['processorInformation']['transactionId'] ?? '',
        ];
    }

    private function resolvePaymentMethod(?Payments $payment): ?PaymentMethods
    {
        if ($payment === null) {
            return null;
        }

        if ($payment->paymentMethod !== null) {
            return $payment->paymentMethod;
        }

        if (empty($payment->payment_methods_id)) {
            return null;
        }

        return PaymentMethods::withoutGlobalScopes()->find($payment->payment_methods_id);
    }
}
