<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Connectors\Tookan\Enums\ConfigurationEnum as TookanConfigEnum;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Notifications\OrderNotification;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

class SendOrderEmailsAction
{
    public function __construct(
        private Order $order,
        private string $emailTemplate,
        private array $data = [],
        private array $channels = ['mail'],
        private ?string $subject = null,
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

        $recipientData = $this->getRecipientData();

        if (! $recipientData || ! $recipientData['email']) {
            return;
        }

        $externalCompany = $this->getExternalCompany();

        $tzName = $this->order->app->get(TookanConfigEnum::TIMEZONE->value) ?? 'UTC';

        $payload = [
            'template' => $this->emailTemplate,
            'order' => $this->order,
            'order_id' => $this->order->getId(),
            'order_status' => $this->order->status,
            'customer_name' => $this->order->people->name ?? 'Customer',
            'company_name' => $this->order->company->name ?? '',
            'recipient_name' => $recipientData['name'] ?? '',
            'external_company' => $externalCompany['name'] ?? null,
            'estimated_delivery_time' => $this->order->estimate_shipping_date
                ? Carbon::parse($this->order->estimate_shipping_date)->timezone($tzName)->format('d/m/Y H:i')
                : null,
            'delivered_time' => $this->order->shipped_date
                ? Carbon::parse($this->order->shipped_date)->timezone($tzName)->format('d/m/Y H:i')
                : null,
            ...$this->data,
        ];

        $this->sendEmail(
            $this->emailTemplate,
            $recipientData['email'],
            $recipientData['name'] ?? '',
            $payload,
            $this->order
        );
    }

    protected function getRecipientData(): ?array
    {
        // Determine recipient based on template prefix
        if (str_starts_with($this->emailTemplate, 'user-')) {
            // Send to customer
            return [
                'email' => $this->order->people->getEmails()->first()?->value,
                'name' => $this->order->people->name ?? 'Customer',
            ];
        }

        if (str_starts_with($this->emailTemplate, 'provider-')) {
            // Send to provider company (external item company)
            $externalItem = $this->order->items->first(function ($item) {
                return $item->variant->companies_id !== $this->order->companies_id;
            });

            if ($externalItem && $externalItem->variant->company) {
                return [
                    'email' => $externalItem->variant->company->user->email,
                    'name' => $externalItem->variant->company->user->displayname ?? $externalItem->variant->company->name,
                ];
            }
        }

        if (str_starts_with($this->emailTemplate, 'owner-')) {
            // Send to main company owner
            return [
                'email' => $this->order->company->user->email,
                'name' => $this->order->company->user->displayname ?? $this->order->company->name,
            ];
        }

        return null;
    }

    private function getExternalCompany()
    {
        $externalItem = $this->order->items->first(function ($item) {
            return $item->variant->companies_id !== $this->order->companies_id;
        });

        if ($externalItem && $externalItem->variant->company) {
            return [
                'email' => $externalItem->variant->company->user->email,
                'name' => $externalItem->variant->company->name,
            ];
        }

        return null;
    }

    protected function sendEmail(
        string $emailTemplateName,
        string $email,
        string $name,
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

        if ($this->subject !== null) {
            $notification->setSubject($this->subject);
        }

        if (in_array('mail', $this->channels)) {
            $mailNotification = clone $notification;
            $mailNotification->channels = ['mail'];
            Notification::route('mail', $email)->notify($mailNotification);
        }

        if (in_array('database', $this->channels)) {
            $user = $this->getNotifiableUser();
            if ($user) {
                $dbNotification = clone $notification;
                $dbNotification->channels = ['database'];
                $user->notify($dbNotification);
            }
        }
    }

    private function getNotifiableUser(): ?Users
    {
        if (str_starts_with($this->emailTemplate, 'user-') && $this->order->users_id) {
            return Users::getById($this->order->users_id);
        }

        if (str_starts_with($this->emailTemplate, 'provider-')) {
            $externalItem = $this->order->items->first(
                fn ($item) => $item->variant->companies_id !== $this->order->companies_id
            );

            if ($externalItem?->variant?->company?->user) {
                return $externalItem->variant->company->user;
            }
        }

        if (str_starts_with($this->emailTemplate, 'owner-')) {
            return $this->order->company->user ?? null;
        }

        return null;
    }
}
