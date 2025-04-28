<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\Actions\SendLeadEmailsAction;
use Kanvas\Guild\Leads\Enums\LeadNotificationModeEnum;
use Kanvas\Guild\Leads\Enums\LeadNotificationUserModeEnum;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Users\Models\Users;

class SendRotationEmailsAction
{
    public function __construct(
        private ModelsLead $lead,
        private LeadReceiver $leadReceiver,
        private LeadRotation|null $leadRotation,
        private Users $user
    ) {
    }

    public function execute(array $payload, string $userFlag = 'user', string|null $defaultEmailTemplate = null)
    {
        $emailTemplate = $this->leadRotation?->config['email_template'] ?? $defaultEmailTemplate;
        
        if ($emailTemplate) {
            $emailReceiverUser = $userFlag === 'user' ? $this->leadReceiver->user : $this->user;
            $notificationMode = isset($this->leadReceiver->rotation->config['notification_mode']) ? LeadNotificationModeEnum::get($this->leadReceiver->rotation->config['notification_mode']) : LeadNotificationModeEnum::NOTIFY_ALL; // leads || agets
            $notificationUserMode = isset($this->leadReceiver->rotation->config['notification_user_mode']) ? LeadNotificationUserModeEnum::get($this->leadReceiver->rotation->config['notification_user_mode']) : LeadNotificationUserModeEnum::NOTIFY_OWNER;
            $users = $notificationUserMode === LeadNotificationUserModeEnum::NOTIFY_ROTATION_USERS && $this->leadReceiver->rotation?->agents?->count() > 0
            ? collect([$emailReceiverUser])
            ->merge($this->leadReceiver->rotation->agents?->pluck('users') ?? [])
            ->flatten()
            ->all()
            : [$emailReceiverUser];

            $this->sendLeadEmails($emailTemplate, $users, $this->lead, $payload, $notificationMode);
        }
    }

    protected function sendLeadEmails(string $emailTemplate, array $users, ModelsLead $lead, array $payload, LeadNotificationModeEnum $notificationMode = LeadNotificationModeEnum::NOTIFY_ALL): void
    {
        $sendLeadEmailsAction = new SendLeadEmailsAction($lead, $emailTemplate);
        $fieldMaps = $this->mapCustomFields($payload['custom_fields']);
        if (isset($fieldMaps['product_id'])) {
            $payload['product'] = $sendLeadEmailsAction->getProduct($fieldMaps['product_id']);
        }
        $sendLeadEmailsAction->execute($payload, $users, $notificationMode);
    }

    protected function mapCustomFields(array $customFields): array
    {
        $fieldMaps = [];
        foreach ($customFields as $customField) {
            $fieldMaps[$customField['name']] = $customField['data'];
        }
        return $fieldMaps;
    }
}
