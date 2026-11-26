<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Notifications\PaymentReceiptNotification;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Templates\Repositories\TemplatesRepository;
use Kanvas\Users\Models\Users;
use Throwable;

class SendPaymentReceiptAction
{
    private array $channels = ['mail'];

    public function __construct(
        private Order $order,
        private Payments $payment,
        private Users $user
    ) {
    }

    public static function trySend(Order $order, Payments $payment): void
    {
        try {
            new self($order, $payment, $payment->user)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function execute(?string $emailTemplate = null): void
    {
        $payload = [
            'order' => $this->order,
            'payment' => $this->payment,
            'paymentMethod' => $this->payment->paymentMethod,
            'user' => $this->user,
            'receipt' => $this->receiptData(),
        ];

        $this->sendEmail(
            $emailTemplate
                ?? $this->processorTemplate()
                ?? $this->order->orderType?->paymentReceiptTemplate()
                ?? OrderTypes::DEFAULT_PAYMENT_RECEIPT_TEMPLATE,
            $this->user->email,
            $payload,
        );
    }

    private function receiptData(): array
    {
        $company = $this->order->company;
        $paymentMethod = $this->payment->paymentMethod;

        return [
            'merchant' => [
                'name' => Str::trimToNull((string) $company->get('commercial_name')) ?? $company->name,
                'legal_name' => Str::trimToNull((string) $company->get('legal_name')),
                'rnc' => Str::trimToNull((string) $company->get('rnc')),
                'address' => implode(', ', array_filter([
                    $company->address,
                    $company->address_2,
                    $company->city,
                    $company->state,
                    $company->country,
                ])),
                'email' => $company->email,
                'phone' => $company->phone,
            ],
            'order_number' => $this->order->order_number,
            'date' => ($this->payment->updated_at ?? now())
                ->copy()
                ->setTimezone($company->getTimezone() ?? config('app.timezone'))
                ->format('d/m/Y h:i A'),
            'order_data' => $this->order->metadata['data'] ?? [],
            'items' => $this->order->items
                ->reject(fn ($item) => (bool) $item->variant?->product?->productsTypes?->exclude_from_receipt)
                ->map(fn ($item) => [
                    'name' => $item->product_name,
                    'quantity' => (float) $item->quantity,
                    'total' => number_format((float) $item->unit_price_gross_amount * (float) $item->quantity, 2),
                ])
                ->values()
                ->all(),
            'tax' => (float) $this->order->tax_amount,
            'total' => number_format((float) $this->payment->amount, 2),
            'currency' => trim((string) ($this->payment->currency ?: $this->order->currency)),
            'card_brand' => $this->payment->payment_method_brand ?: $paymentMethod?->payment_methods_brand,
            'card_last_four' => $this->payment->payment_method_last_four ?: $paymentMethod?->payment_ending_numbers,
            'authorization_code' => $this->payment->authorization_code,
            'processor' => $this->payment->processorName(),
        ];
    }

    private function processorTemplate(): ?string
    {
        $processor = Str::trimToNull($this->payment->processorName());

        if ($processor === null) {
            return null;
        }

        $name = OrderTypes::DEFAULT_PAYMENT_RECEIPT_TEMPLATE . '_' . $processor;

        try {
            TemplatesRepository::getByName($name, $this->order->app, $this->order->company);
        } catch (ModelNotFoundException) {
            return null;
        }

        return $name;
    }

    protected function sendEmail(
        string $emailTemplateName,
        string $email,
        array $mailData
    ): void {
        $notification = new PaymentReceiptNotification(
            $this->order,
            $this->payment,
            $mailData,
        );
        $notification->setTemplateName($emailTemplateName);
        $notification->setSubject('Comprobante de pago · Orden #' . $this->order->order_number);
        $notification->setType(NotificationTypes::firstOrCreate([
            'apps_id' => $this->order->app->getId(),
            'key' => $this->order::class,
            'name' => Str::simpleSlug($this->order::class),
            'system_modules_id' => SystemModulesRepository::getByModelName($this->order::class, $this->order->app)->getId(),
            'is_deleted' => 0,
        ], [
            'template' => $emailTemplateName,
        ])->name);

        $notification->channels = $this->channels;

        Notification::route('mail', $email)->notify($notification);
    }
}
