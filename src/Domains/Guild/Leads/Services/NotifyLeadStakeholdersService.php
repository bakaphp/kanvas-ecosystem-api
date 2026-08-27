<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Notifications\Notification as NotificationsNotification;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Notifications\Templates\EngagementNotification;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class NotifyLeadStakeholdersService
{
    public const string LAST_MANAGER_NOTIFICATION_AT = 'lead_last_manager_notification_at';
    public const string NOTIFY_ON_AGENT_REPLY = 'ai_manager_notify_on_agent_reply';
    public const string NOTIFY_ON_HUMAN_REPLY = 'ai_manager_notify_on_human_reply';
    public const string NOTIFY_ON_INBOUND = 'ai_manager_notifications';
    public const string MANAGER_ROLE = 'BDCManager';

    public const string LAST_AGENT_REPLY_NOTIFICATION_AT = 'last_agent_reply_notification_at';
    public const string AGENT_REPLY_DEDUPE_SECONDS_KEY = 'agent_reply_notification_dedupe_seconds';
    public const int AGENT_REPLY_DEDUPE_SECONDS_DEFAULT = 5;

    public const string LAST_CUSTOMER_ENGAGEMENT_NOTIFICATION_AT = 'last_customer_engagement_notification_at';
    public const string CUSTOMER_ENGAGEMENT_DEDUPE_SECONDS_KEY = 'customer_engagement_notification_dedupe_seconds';
    public const int CUSTOMER_ENGAGEMENT_DEDUPE_SECONDS_DEFAULT = 1;

    public function __construct(
        protected Lead $lead,
        protected ?NotificationsNotification $notification = null,
    ) {
    }

    public function all(): void
    {
        $this->owner();
        $this->managers();
        $this->followers();
    }

    public function owner(): void
    {
        if ($this->notification === null) {
            return;
        }

        if ($this->lead->owner) {
            $this->lead->owner->notify($this->notification);
        }
    }

    public function managers(): void
    {
        if ($this->notification === null) {
            return;
        }

        $companyManagers = $this->lead->company->get('company_manager');

        if ($this->lead->get('sent_email_notification_to_manager')) {
            return;
        }

        if ($companyManagers && is_array($companyManagers)) {
            $this->lead->set('sent_email_notification_to_manager', 1);

            $users = Users::whereIn('id', $companyManagers)->get();

            Notification::send($users, $this->notification);
        }
    }

    public function followers(): void
    {
        if ($this->notification === null) {
            return;
        }

        if ($this->lead->getTotalFollowers($this->lead->app) === 0) {
            return;
        }
        foreach ($this->lead->followers as $follower) {
            if ($follower->id !== $this->lead->owner->getId()) {
                $follower->notify($this->notification);
            }
        }
    }

    /**
     * Notify lead owner + BDC managers when a customer engages on a channel.
     *
     * Honors company config:
     * - ai_manager_notifications (off → skip entirely)
     * - first_engagement_notification_channels / engagement_notification_channels (slug list)
     * - ai_manager_notification_only_sms / _only_mail / _only_push (legacy fallbacks)
     * - ai_engagement_message_only_one_notification (dedupe via lead_last_manager_notification_at)
     * - work hours via CompanyWorkHoursTool
     *
     * Channel slugs ('sms', 'push', 'expo', 'mail', 'database') flow straight to
     * $notification->channels — the base Notification::via() resolves them via
     * NotificationChannelEnum::getNotificationChannelBySlug(). No service-level
     * slug-to-class mapping.
     */
    public function onCustomerEngagement(Message $message, bool $includeOwner = true): array
    {
        if (! $this->lead->company->get(self::NOTIFY_ON_INBOUND)) {
            return ['skipped' => 'inbound_notifications_disabled'];
        }

        $hoursTool = new CompanyWorkHoursTool($message)->execute();
        if ($hoursTool['status'] !== 'work_hours') {
            return ['skipped' => 'outside_work_hours'];
        }

        if ($this->lead->company->get(IntelligenceConfigurationEnum::AI_ENGAGEMENT_MESSAGE_ONLY_ONE_NOTIFICATION->value)
            && $this->lead->get(self::LAST_MANAGER_NOTIFICATION_AT)) {
            return ['skipped' => 'already_notified'];
        }

        /** @var Channel|null $channel */
        $channel = $message->channels()->first();
        if ($channel instanceof Channel && $this->isWithinCustomerEngagementDedupeWindow($channel)) {
            return ['skipped' => 'already_notified_within_window'];
        }

        $isFirst = $this->isFirstEngagement($message);
        $channels = $this->resolveEngagementChannels($isFirst);
        if (empty($channels)) {
            return ['skipped' => 'no_channels_configured'];
        }

        $notification = new EngagementNotification(
            templateName: 'agent-manager-notification',
            data: [
                'message' => $message,
                'company' => $message->company,
                'app' => $message->app,
                'user' => $message->user,
                'lead_name' => $this->lead->people->name,
                'lead_id' => $this->lead->getId(),
                'people_id' => $this->lead->people->getId(),
                'branch_id' => $this->lead->companies_branches_id,
            ],
            via: $channels,
            entity: $this->lead
        );

        $peopleName = (string) ($this->lead->people->name ?? '');
        $notification->setSubject($peopleName . ' Engaged with Sally');
        $notification->setPushTemplateName('agent_manager_push_notification');
        $notification->setSmsTemplateName('agent_manager_sms_notification');
        $notification->setDatabaseTemplateName('agent_manager_sms_notification');

        $recipients = $this->collectManagerRecipients($message, $includeOwner);

        if ($recipients->isEmpty()) {
            return ['skipped' => 'no_recipients'];
        }

        Notification::send($recipients, $notification);

        $this->lead->set(self::LAST_MANAGER_NOTIFICATION_AT, date('Y-m-d H:i:s'));

        if ($channel instanceof Channel) {
            $channel->set(self::LAST_CUSTOMER_ENGAGEMENT_NOTIFICATION_AT, date('Y-m-d H:i:s'));
        }

        return [
            'channels' => $channels,
            'is_first_engagement' => $isFirst,
            'channel_id' => $channel?->getId(),
            'channel_name' => $channel?->name,
            'channel_slug' => $channel?->slug,
            'manager_count' => $recipients->count(),
            'manager_ids' => $recipients->pluck('id')->toArray(),
        ];
    }

    /**
     * Notify lead owner + BDC managers when an agent (AI or human) replies to a customer.
     *
     * Internal-only by default: 'database' channel — recipients see it in the in-app feed
     * without getting SMS/email/push spam every time the agent answers.
     *
     * Gated by separate company flags from inbound:
     * - ai_manager_notify_on_agent_reply (AI replies, default off)
     * - ai_manager_notify_on_human_reply (human replies, default off)
     */
    public function onAgentReply(
        Message $message,
        bool $isHuman = false,
        bool $includeOwner = true
    ): array {
        $flag = $isHuman ? self::NOTIFY_ON_HUMAN_REPLY : self::NOTIFY_ON_AGENT_REPLY;

        if (! $this->lead->company->get($flag)) {
            return ['skipped' => $flag . '_disabled'];
        }

        /** @var Channel|null $channel */
        $channel = $message->channels()->first();
        if ($channel instanceof Channel && $this->isWithinAgentReplyDedupeWindow($channel)) {
            return ['skipped' => 'already_notified_within_window'];
        }

        $channels = ['database'];

        $notification = new Blank(
            templateName: 'agent-reply-manager-notification',
            data: [
                'message' => $message,
                'company' => $message->company,
                'app' => $message->app,
                'user' => $message->user,
                'is_human' => $isHuman,
                'lead_name' => $this->lead->people->name,
                'lead_id' => $this->lead->getId(),
                'people_id' => $this->lead->people->getId(),
                'branch_id' => $this->lead->companies_branches_id,
            ],
            via: $channels,
            entity: $this->lead
        );

        $peopleName = (string) ($this->lead->people->name ?? '');
        $notification->setSubject(
            ($isHuman ? 'Agent ' : 'Sally ') . 'replied to ' . $peopleName
        );

        $recipients = $this->collectManagerRecipients($message, $includeOwner);

        if ($recipients->isEmpty()) {
            return ['skipped' => 'no_recipients'];
        }

        Notification::send($recipients, $notification);

        if ($channel !== null) {
            $channel->set(self::LAST_AGENT_REPLY_NOTIFICATION_AT, date('Y-m-d H:i:s'));
        }

        return [
            'channels' => $channels,
            'is_human' => $isHuman,
            'channel_id' => $channel?->getId(),
            'channel_name' => $channel?->name,
            'channel_slug' => $channel?->slug,
            'manager_count' => $recipients->count(),
            'manager_ids' => $recipients->pluck('id')->toArray(),
        ];
    }

    protected function isWithinAgentReplyDedupeWindow(Channel $channel): bool
    {
        return $this->isWithinDedupeWindow(
            $channel,
            self::LAST_AGENT_REPLY_NOTIFICATION_AT,
            self::AGENT_REPLY_DEDUPE_SECONDS_KEY,
            self::AGENT_REPLY_DEDUPE_SECONDS_DEFAULT
        );
    }

    protected function isWithinCustomerEngagementDedupeWindow(Channel $channel): bool
    {
        return $this->isWithinDedupeWindow(
            $channel,
            self::LAST_CUSTOMER_ENGAGEMENT_NOTIFICATION_AT,
            self::CUSTOMER_ENGAGEMENT_DEDUPE_SECONDS_KEY,
            self::CUSTOMER_ENGAGEMENT_DEDUPE_SECONDS_DEFAULT
        );
    }

    /**
     * Window dedupe: returns true if a notification was fired on this channel
     * within `now - $windowSeconds`. Window is read from company config when set,
     * otherwise the default. Setting the company config to 0 disables dedupe.
     */
    protected function isWithinDedupeWindow(
        Channel $channel,
        string $timestampKey,
        string $windowConfigKey,
        int $windowDefault
    ): bool {
        $configured = $this->lead->company->get($windowConfigKey);
        $window = is_numeric($configured) ? (int) $configured : $windowDefault;

        if ($window <= 0) {
            return false;
        }

        $lastAt = $channel->get($timestampKey);

        if (empty($lastAt)) {
            return false;
        }

        return strtotime((string) $lastAt) > (time() - $window);
    }

    /**
     * Pick the slug list for first-vs-subsequent engagement, falling back to legacy
     * ai_manager_notification_only_* flags when neither engagement list is configured.
     */
    protected function resolveEngagementChannels(bool $isFirst): array
    {
        $firstEngagementChannels = (array) ($this->lead->company->get(IntelligenceConfigurationEnum::FIRST_ENGAGEMENT_NOTIFICATION_CHANNELS->value) ?? []);
        $subsequentEngagementChannels = (array) ($this->lead->company->get(IntelligenceConfigurationEnum::ENGAGEMENT_NOTIFICATION_CHANNELS->value) ?? []);

        if (! empty($firstEngagementChannels) || ! empty($subsequentEngagementChannels)) {
            return $isFirst ? $firstEngagementChannels : $subsequentEngagementChannels;
        }

        $onlySms = (bool) $this->lead->company->get('ai_manager_notification_only_sms');
        $onlyMail = (bool) $this->lead->company->get('ai_manager_notification_only_mail');
        $onlyPush = (bool) $this->lead->company->get('ai_manager_notification_only_push');

        return match (true) {
            $onlySms => ['sms', 'database'],
            $onlyMail => ['mail'],
            $onlyPush => ['push', 'expo', 'database'],
            default => ['mail'],
        };
    }

    protected function isFirstEngagement(Message $message): bool
    {
        $channel = $message->channels()->first();

        if ($channel === null) {
            return true;
        }

        $previousInboundCount = $channel->messages()
            ->whereRaw("JSON_EXTRACT(messages.message, '$.from_me') = false")
            ->where('messages.is_deleted', 0)
            ->where('messages.id', '!=', $message->getId())
            ->count();

        return $previousInboundCount === 0;
    }

    /**
     * BDC managers + lead owner, deduped by user id.
     */
    protected function collectManagerRecipients(Message $message, bool $includeOwner): Collection
    {
        $managers = UsersRepository::getCompanyAppUserByRole(
            $message->company,
            $message->app,
            self::MANAGER_ROLE
        )->get();

        if ($includeOwner) {
            $owner = $this->lead->owner;
            if ($owner && ! $managers->contains('id', $owner->getId())) {
                $managers->push($owner);
            }
        }

        return $managers;
    }
}
