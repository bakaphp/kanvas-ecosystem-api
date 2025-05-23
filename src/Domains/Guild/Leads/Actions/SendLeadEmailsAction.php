<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Guild\Leads\Enums\LeadNotificationModeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Notifications\NewLeadNotification;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;

class SendLeadEmailsAction
{
    public function __construct(
        private Lead $lead,
        private string $emailTemplate,
        private mixed $channels = ['mail']
    ) {
    }

    public function execute(
        array $payload,
        array $users,
        LeadNotificationModeEnum $notificationMode = LeadNotificationModeEnum::NOTIFY_ALL,
    ): void {
        $userTemplate = 'user-' . $this->emailTemplate;
        $leadTemplate = 'lead-' . $this->emailTemplate;
        $data = [
            ...$payload,
            'lead' => $this->lead,
        ];
        $leadEmail = $this->lead->people()->first()->emails()->first()?->value;
        $shouldSendToUser = $notificationMode === LeadNotificationModeEnum::NOTIFY_ALL || $notificationMode === LeadNotificationModeEnum::NOTIFY_AGENTS;
        $shouldSendToLead = $leadEmail && ($notificationMode === LeadNotificationModeEnum::NOTIFY_LEAD || $notificationMode === LeadNotificationModeEnum::NOTIFY_ALL);

        if ($shouldSendToUser) {
            foreach ($users as $user) {
                try {
                    $this->sendEmail(
                        $user,
                        $userTemplate,
                        $user->email,
                        $data
                    );
                } catch (Exception $e) {
                    report($e);

                    continue;
                }
            }
        }
        if ($shouldSendToLead) {
            $this->sendEmail(
                $this->lead,
                $leadTemplate,
                $leadEmail,
                $data
            );
        }
    }

    public function getProduct(string $productId): object
    {
        $product = Products::where('id', $productId)->with(['variants', 'variants.warehouses'])->first();
        $variant = $product->variants->first();
        $warehouse = $variant->warehouses->first();
        $defaultChannel = $variant->channels->first();

        return (object) [
            'name' => $product->name,
            'price' => $variant->getPrice($warehouse, $defaultChannel),
            'quantity' => $variant->quantity,
        ];
    }

    protected function sendEmail(
        Model $entity,
        string $emailTemplateName,
        string $email,
        array $mailData
    ): void {
        $notification = new NewLeadNotification(
            $this->lead,
            $mailData,
        );
        $notification->setTemplateName($emailTemplateName);
        $notification->setType(NotificationTypes::firstOrCreate([
            'apps_id' => $this->lead->app->getId(),
            'key' => $this->lead::class,
            'name' => Str::simpleSlug($this->lead::class),
            'system_modules_id' => SystemModulesRepository::getByModelName($this->lead::class, $this->lead->app)->getId(),
            'is_deleted' => 0,
        ], [
            'template' => $emailTemplateName,
        ])->name);

        $notification->channels = $this->channels;

        if ($entity instanceof Users) {
            $entity->notify($notification);
        } else {
            Notification::route('mail', $email)->notify($notification);
        }
    }
}
