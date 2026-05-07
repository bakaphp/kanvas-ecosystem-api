<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\Enums\LeadNotificationModeEnum;
use Kanvas\Guild\Leads\Enums\LeadNotificationUserModeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Users\Models\Users;

class SendRotationEmailsAction
{
    public function __construct(
        private readonly Lead $lead,
        private readonly LeadReceiver $leadReceiver,
        private readonly ?LeadRotation $leadRotation,
        private readonly Users $user,
    ) {
    }

    public function execute(
        array $payload,
        string $userFlag = 'user',
        ?string $defaultEmailTemplate = null,
    ): void {
        $template = $this->leadRotation?->config['email_template'] ?? $defaultEmailTemplate;

        if ($template === null) {
            return;
        }

        $owner = $userFlag === 'user' ? $this->leadReceiver->user : $this->user;
        $activeUsers = $this->leadRotation?->getActiveUsers() ?? collect();
        $shouldNotifyAgents = $this->resolveNotificationUserMode() === LeadNotificationUserModeEnum::NOTIFY_ROTATION_USERS
            && $activeUsers->isNotEmpty();

        $recipients = $shouldNotifyAgents
            ? collect([$owner])->merge($activeUsers)->all()
            : [$owner];

        if ($shouldNotifyAgents) {
            $payload['extraEmails'] = $this->leadRotation->leads_rotations_email;
        }

        $sender = new SendLeadEmailsAction(
            $this->lead,
            $template,
            $this->resolveChannels()
        );
        $sender->execute(
            $this->enrichPayload($payload, $sender),
            $recipients,
            $this->resolveNotificationMode(),
        );
    }

    private function resolveNotificationMode(): LeadNotificationModeEnum
    {
        $value = $this->leadRotation?->config['notification_mode'] ?? null;

        return $value !== null
            ? LeadNotificationModeEnum::get($value)
            : LeadNotificationModeEnum::NOTIFY_ALL;
    }

    private function resolveNotificationUserMode(): LeadNotificationUserModeEnum
    {
        $value = $this->leadRotation?->config['notification_user_mode'] ?? null;

        return $value !== null
            ? LeadNotificationUserModeEnum::get($value)
            : LeadNotificationUserModeEnum::NOTIFY_OWNER;
    }

    /**
     * @return array<string>
     */
    private function resolveChannels(): array
    {
        $channels = ['mail'];
        if (($this->leadRotation?->config['notification_channels'] ?? null) === 'database') {
            $channels[] = 'database';
        }

        return $channels;
    }

    private function enrichPayload(array $payload, SendLeadEmailsAction $sender): array
    {
        $customFields = $payload['custom_fields'] ?? [];
        $fieldMaps = $this->mapCustomFields($customFields);

        if (isset($fieldMaps['product_id'])) {
            $payload['product'] = $sender->getProduct($fieldMaps['product_id']);
        }

        $payload['field_maps'] = $fieldMaps;
        $payload['field_maps_with_labels'] = $this->mapCustomFieldsWithLabels($customFields);
        $payload['photo'] = $this->lead->company?->getPhoto()?->url;

        return $payload;
    }

    private function mapCustomFields(array $customFields): array
    {
        $fieldMaps = [];
        foreach ($customFields as $customField) {
            if (! isset($customField['name'])) {
                continue;
            }
            $fieldMaps[$customField['name']] = $customField['data'] ?? null;
        }

        return $fieldMaps;
    }

    private function mapCustomFieldsWithLabels(array $customFields): array
    {
        $formLabels = $this->lead->app->get('JSON_FORM_LABELS') ?? [];
        $fieldMaps = [];

        foreach ($customFields as $customField) {
            if (! isset($customField['name'])) {
                continue;
            }
            $name = $customField['name'];
            $fieldMaps[$name] = [
                'data' => $customField['data'] ?? null,
                'label' => $formLabels[$name] ?? $name,
            ];
        }

        return $fieldMaps;
    }
}
