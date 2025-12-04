<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\OrderNotification;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class SendOrderEmailsAction
{
    private array $channels = ['mail'];

    public function __construct(
        private Order $order,
        private string $emailTemplate,
        private array $data = []
    ) {
    }

    public function execute(): void
    {
        // Load necessary relations to ensure they're available in email templates
        $this->order->load([
            'orderType',
            'orderStatus',
            'people',
            'items.variant',
            'company',
        ]);

        $recipientEmail = $this->getRecipientEmail();

        if (! $recipientEmail) {
            return;
        }

        $payload = [
            'template' => $this->emailTemplate,
            'order' => $this->order,
            'order_id' => $this->order->getId(),
            'order_status' => $this->order->status,
            'customer_name' => $this->order->people->name ?? 'Customer',
            'company_name' => $this->order->company->name ?? '',
            ...$this->data,
        ];

        $this->sendEmail(
            $this->emailTemplate,
            $recipientEmail,
            $payload,
            $this->order
        );
    }

    protected function getRecipientEmail(): ?string
    {
        // Determine recipient based on template prefix
        if (str_starts_with($this->emailTemplate, 'user-')) {
            // Send to customer
            return $this->order->people->getEmails()->first()?->value;
        }

        if (str_starts_with($this->emailTemplate, 'provider-')) {
            // Send to provider company (external item company)
            $externalItem = $this->order->items->first(function ($item) {
                return $item->variant->companies_id !== $this->order->companies_id;
            });

            if ($externalItem && $externalItem->variant->company) {
                return $externalItem->variant->company->user->email;
            }
        }

        if (str_starts_with($this->emailTemplate, 'owner-')) {
            // Send to main company owner
            return $this->order->company->user->email;
        }

        return null;
    }

    protected function sendEmail(
        string $emailTemplateName,
        string $email,
        array $mailData,
        Order $order
    ): void {
        $notification = new OrderNotification(
            $order,
            $mailData,
        );
        $notification->setTemplateName($emailTemplateName);
        $notification->setType(NotificationTypes::firstOrCreate([
            'apps_id' => $order->app->getId(),
            'key' => $order::class,
            'name' => Str::simpleSlug($order::class),
            'system_modules_id' => SystemModulesRepository::getByModelName($order::class, $order->app)->getId(),
            'is_deleted' => 0,
        ], [
            'template' => $emailTemplateName,
        ])->name);

        $notification->channels = $this->channels;

        Notification::route('mail', $email)->notify($notification);
    }
}
