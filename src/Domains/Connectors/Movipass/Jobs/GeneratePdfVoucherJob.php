<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

class GeneratePdfVoucherJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public array $result = [];

    public function __construct(
        public Order $entity,
        public UserInterface $user,
        public string $html = '',
        public string $filename = '',
        public array $data = [],
    ) {
    }

    public function handle(): void
    {
        $templateData = array_merge(
            $this->resolveVoucherData($this->entity),
            $this->data,
        );

        $pdfFile = PdfService::generatePdfFromTemplate(
            $this->entity->app,
            $this->user,
            $this->html,
            $this->entity,
            $templateData,
            []
        );

        $this->entity->addFile($pdfFile, $this->filename);
        $this->entity->set("voucher_url", $pdfFile->url);

        activity()
        ->causedBy($this->user)
        ->performedOn($this->entity)
        ->withProperties([
            'order_id' => $this->entity->id,
            'order_number' => $this->entity->order_number,
            'user_id' => $this->user->id,
            'timestamp' => now(),
            'file_id' => $pdfFile->id,
            'file_url' =>  $pdfFile->url,
            'file_path' => $pdfFile->path
        ])
        ->log('COMPROBANTE_DESPACHO_GENERADO');
    }

    public function resolveVoucherData(Order $order): array
    {
        $payment = $order->payments
            ->whereIn('status', [
                PaymentStatusEnum::PAID->value
            ])
            ->first();

        $paymentMethod = $this->resolvePaymentMethod($payment);
        $metadataCard = $this->extractCardFromMetadata($payment);

        $paymentMethodName = match (true) {
            filled($payment?->payment_method_brand) => trim(
                $payment->payment_method_brand . ' ' . ($payment->payment_method_last_four ?? '')
            ),
            filled($paymentMethod?->payment_methods_brand) => trim(
                $paymentMethod->payment_methods_brand . ' ' . ($paymentMethod->payment_ending_numbers ?? '')
            ),
            filled($metadataCard['brand']) => trim(
                $metadataCard['brand'] . ' ' . ($metadataCard['last_four'] ?? '')
            ),
            filled($payment?->payment_method) => ucfirst((string) $payment->payment_method),
            default => null,
        };

        return [
            'paymentDate' => $order->metadata['data']['payment_date'] ?? '',
            'releaseDate' => $order->metadata['data']['release_date'] ?? null,
            'paymentType' => $paymentMethodName ?? 'Transferencia/Depósito',
            'transactionNumber' => $payment?->metadata['data']['payment_response']['processorInformation']['transactionId'] ?? '',
        ];
    }

    private function resolvePaymentMethod(?Payments $payment): ?PaymentMethods
    {
        if ($payment === null) {
            return null;
        }

        $related = $payment->paymentMethod;
        if ($related !== null) {
            return $related;
        }

        if (empty($payment->payment_methods_id)) {
            return null;
        }

        return PaymentMethods::withoutGlobalScopes()->find($payment->payment_methods_id);
    }

    /**
     * @return array{brand: ?string, last_four: ?string}
     */
    private function extractCardFromMetadata(?Payments $payment): array
    {
        $brand = $payment?->metadata['enrollment_data']['paymentInformation']['card']['type'] ?? null;

        return [
            'brand' => $brand ? strtoupper((string) $brand) : null,
            'last_four' => null,
        ];
    }
}
