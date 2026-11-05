<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Notifications\PaymentReceiptNotification;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
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
            new self(
                $order,
                $payment,
                $payment->user
            )->execute();
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
        ];

        $this->sendEmail(
            $emailTemplate ?? $this->order->orderType?->paymentReceiptTemplate() ?? OrderTypes::DEFAULT_PAYMENT_RECEIPT_TEMPLATE,
            $this->user->email,
            $payload,
        );
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
