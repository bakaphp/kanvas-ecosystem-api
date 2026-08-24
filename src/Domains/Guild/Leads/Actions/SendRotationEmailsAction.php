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

        $extraEmails = $this->resolveExtraEmails($shouldNotifyAgents);

        if ($extraEmails !== null) {
            $payload['extraEmails'] = $extraEmails;
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

    // Commas are trimmed alongside whitespace so a stray "," falls through to the rotation instead
    // of parsing down to zero recipients.
    private function resolveExtraEmails(bool $shouldNotifyAgents): ?string
    {
        $receiverEmails = trim((string) $this->leadReceiver->notification_email, ", \t\n\r\0\x0B");

        if ($receiverEmails !== '') {
            return $receiverEmails;
        }

        return $shouldNotifyAgents ? $this->leadRotation->leads_rotations_email : null;
    }

    /**
     * Reads `rotation.config.notification_mode` — controls WHO on the recipient axis gets the email.
     *
     * - NOTIFY_ALL (default) → both agents and the lead's contact email
     * - NOTIFY_AGENTS        → agents only (lead does not get an email)
     * - NOTIFY_LEAD          → the lead's contact email only (agents do not get an email)
     *
     * Note: `extraEmails` is unaffected by this mode — see resolveExtraEmails().
     */
    private function resolveNotificationMode(): LeadNotificationModeEnum
    {
        $value = $this->leadRotation?->config['notification_mode'] ?? null;

        return $value !== null
            ? LeadNotificationModeEnum::get($value)
            : LeadNotificationModeEnum::NOTIFY_ALL;
    }

    /**
     * Reads `rotation.config.notification_user_mode` — controls WHICH users on the agent side get
     * the email.
     *
     * - NOTIFY_OWNER (default) → only the receiver's owner (LeadReceiver.users_id)
     * - NOTIFY_ROTATION_USERS  → owner + every active rotation agent, AND
     *                            rotation.leads_rotations_email CCs are injected
     *
     * Quirks:
     *   - NOTIFY_ROTATION_USERS notifies ALL active rotation agents, not just the round-robin
     *     assignee for this lead.
     *   - If the rotation has zero active agents, this mode silently falls back to NOTIFY_OWNER
     *     behavior (and the rotation's `extraEmails` does not fire; the receiver's still does).
     */
    private function resolveNotificationUserMode(): LeadNotificationUserModeEnum
    {
        $value = $this->leadRotation?->config['notification_user_mode'] ?? null;

        return $value !== null
            ? LeadNotificationUserModeEnum::get($value)
            : LeadNotificationUserModeEnum::NOTIFY_OWNER;
    }

    /**
     * Reads `rotation.config.notification_channels` — additional Laravel notification channels
     * stacked on top of the default `mail` channel.
     *
     * Currently only the literal string 'database' is recognized; anything else is ignored. If set,
     * the notification is also persisted to the database notifications table for in-app inboxes.
     *
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
