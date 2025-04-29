<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Kanvas\Guild\Leads\Enums\LeadNotificationModeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Notifications\Templates\Blank;

class SendLeadEmailsAction
{
    public function __construct(
        private Lead $lead,
        private string $emailTemplate
    ) {
    }

    public function execute(
        array $payload,
        array $users,
        LeadNotificationModeEnum $notificationMode = LeadNotificationModeEnum::NOTIFY_ALL
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
                    $this->sendEmail($user, $userTemplate, $user->email, $data);
                } catch (Exception) {
                    continue;
                }
            }
        }
        if ($shouldSendToLead) {
            $this->sendEmail($this->lead, $leadTemplate, $leadEmail, $data);
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

    /**
    * Send email to user or lead using a custom template
    */
    protected function sendEmail(
        Model $entity,
        string $emailTemplateName,
        string $email,
        array $mailData
    ): void {
        $notification = new Blank(
            $emailTemplateName,
            $mailData,
            ['mail'],
            $entity
        );
        Notification::route('mail', $email)->notify($notification);
    }
}
