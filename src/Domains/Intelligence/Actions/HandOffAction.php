<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Actions;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Companies\Services\CompanyManagerService;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeDto;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadHandOffNotification;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\HandOffTypeEnum;
use Kanvas\Intelligence\Notifications\HandOffNotification;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class HandOffAction
{
    private const string DEFAULT_MANAGER_ROLE = 'Manager';
    private const string SERVICE_MANAGER_ROLE = 'ServiceManager';
    private const string HANDOFF_NOTIFICATIONS_ROLE = 'HandoffNotifications';
    private const string DEFAULT_TEMPLATE_NAME = 'lead_handoff';
    private const string SERVICE_LEAD_TYPE_NAME = 'Service';
    private const string LEGACY_DEDUP_FIELD = 'handoff_dedup';
    private const string MAX_NOTIFICATIONS_SETTING = 'ai_handoff_max_notifications';
    private const int DEFAULT_MAX_NOTIFICATIONS = 1;

    public function __construct(
        protected readonly Lead $lead,
        protected readonly AppInterface $app,
        protected readonly array $params = [],
    ) {
    }

    public function execute(): array
    {
        $handOffType = HandOffTypeEnum::tryFrom(strtolower((string) ($this->params['handoff_type'] ?? '')))
            ?? HandOffTypeEnum::HUMAN;

        $notificationsSent = $this->notificationsSent();
        $maxNotifications = $this->maxNotificationsAllowed();

        // Claim before anything can notify, and claim atomically: HandOffActivity retries 3x,
        // fireHandOffWorkflow() re-enters this action through the AI trigger, and the tool and
        // workflow lanes can fire concurrently.
        $claimedSequence = $this->claimNotificationSlot($notificationsSent, $maxNotifications, $handOffType);

        if ($claimedSequence === null) {
            return [
                'success' => true,
                'message' => 'Handoff already processed (duplicate notification prevented)',
                'duplicate' => true,
                'notifications_sent' => max($notificationsSent, $maxNotifications),
                'max_notifications' => $maxNotifications,
            ];
        }

        $leadOwner = $this->getLeadOwner();
        $handOffUserRole = $this->getHandOffUserRole($handOffType);

        $this->fireHandOffWorkflow($handOffType);
        $this->applyHandOffType($handOffType);

        $this->lead->set(ConfigurationEnum::AGENT_HAND_OFF->value, 1);
        $this->lead->set(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value, $handOffType->value);

        $handOffNotification = $this->createHandOffNotification(
            $leadOwner,
            $handOffType,
        );
        $leadOwner->notify($handOffNotification);

        $managersNotified = $this->notifyManagers(
            $leadOwner,
            $handOffNotification,
            $handOffUserRole,
        );

        return [
            'success' => true,
            'message' => 'Handoff processed successfully to ' . $leadOwner->displayname,
            'manager_notified' => $managersNotified,
            'notifications_sent' => $claimedSequence,
            'max_notifications' => $maxNotifications,
        ];
    }

    protected function getLeadOwner(): Users
    {
        if (! empty($this->params['rotation_id'])) {
            try {
                $rotation = LeadRotation::getById($this->params['rotation_id'], $this->app);
                $agent = $rotation->getAgent();
                if ($agent) {
                    $this->lead->leads_owner_id = $agent->getId();
                    $this->lead->saveOrFail();

                    return $agent;
                }
            } catch (Exception) {
            }
        }

        return $this->lead->owner ?? $this->lead->user;
    }

    protected function fireHandOffWorkflow(HandOffTypeEnum $handOffType): void
    {
        $triggerType = $handOffType === HandOffTypeEnum::HUMAN
            ? TriggersEnum::HUMAN_HANDOFF->value
            : TriggersEnum::HANDOFF->value;

        $this->lead->fireWorkflow(
            WorkflowEnum::TRIGGER_AI->value,
            true,
            [
                'app' => $this->app,
                'trigger_type' => $triggerType,
            ]
        );
    }

    protected function applyHandOffType(HandOffTypeEnum $handOffType): void
    {
        if ($handOffType === HandOffTypeEnum::SERVICE) {
            $serviceLeadType = $this->getOrCreateServiceLeadType();
            $this->lead->leads_types_id = $serviceLeadType->getId();
            $this->lead->saveOrFail();
        }

        if ($handOffType === HandOffTypeEnum::COMPLIANCE_INTERNAL) {
            $this->lead->people->optOutPhoneContacts();
        }
    }

    protected function getHandOffUserRole(HandOffTypeEnum $handOffType): string
    {
        return $handOffType === HandOffTypeEnum::SERVICE
            ? self::SERVICE_MANAGER_ROLE
            : ($this->lead->company->get('ai_agent_handoff_user_role') ?? self::HANDOFF_NOTIFICATIONS_ROLE ?? self::DEFAULT_MANAGER_ROLE);
    }

    protected function createHandOffNotification(
        Users $leadOwner,
        HandOffTypeEnum $handOffType,
    ): HandOffNotification {
        $notification = new HandOffNotification(
            lead: $this->lead,
            templateName: $this->params['template_name'] ?? self::DEFAULT_TEMPLATE_NAME,
            data: [
                'lead' => $this->lead,
                'agent' => $leadOwner,
                'company' => $this->lead->company,
                'app' => $this->lead->app,
                'user' => $leadOwner,
                'handoff_type' => $handOffType->value,
                'lead_name' => $this->lead->people->name,
                'lead_id' => $this->lead->getId(),
                'people_id' => $this->lead->people->getId(),
                'branch_id' => $this->lead->companies_branches_id,
                ...$this->params,
            ]
        );

        $this->configureNotificationChannels($notification, $handOffType);

        return $notification;
    }

    protected function configureNotificationChannels(
        HandOffNotification $notification,
        HandOffTypeEnum $handOffType,
    ): void {
        $companyHandOffOnlySms = (bool) $this->lead->company->get('ai_human_handoff_only_sms');
        $companyHandOffOnlyMail = (bool) $this->lead->company->get('ai_human_handoff_only_mail');
        $companyComplianceHandOffOnlyPush = (bool) $this->lead->company->get('ai_compliance_handoff_only_push');

        // SMS wins when a tenant sets both flags: it is the narrower channel of the two. Neither
        // is gated on the handoff type — a tenant that says "only sms" means every handoff, not
        // just the human ones.
        if ($companyHandOffOnlySms) {
            $notification->channels = ['sms'];
        } elseif ($companyHandOffOnlyMail) {
            $notification->channels = ['mail'];
        }

        if ($handOffType === HandOffTypeEnum::COMPLIANCE_INTERNAL) {
            $notification->setTemplateName('lead_handoff_compliance_handoff');
            $notification->setSubject('Lead Compliance Handoff Notification - ' . $this->lead->people->name);
            $notification->setPushTemplateName('lead_handoff_compliance_push_notification');
            $notification->setSmsTemplateName('lead_handoff_compliance_sms_notification');
            $notification->setDatabaseTemplateName('lead_handoff_compliance_sms_notification');

            if ($companyComplianceHandOffOnlyPush && ! $companyHandOffOnlySms && ! $companyHandOffOnlyMail) {
                $notification->setChannelOnlyPush();
            }
        }

        $notification->setDatabaseTemplateName('lead_handoff_db');
    }

    protected function notifyManagers(
        Users $leadOwner,
        HandOffNotification $notification,
        string $handOffUserRole,
    ): int {
        $managers = new CompanyManagerService($this->lead->company, $this->lead->app)
            ->getManagersByRole($handOffUserRole);

        $notifiedCount = 0;
        foreach ($managers as $manager) {
            if ($leadOwner->getId() !== $manager->getId()) {
                $manager->notify($notification);
                $notifiedCount++;
            }
        }

        return $notifiedCount;
    }

    protected function getOrCreateServiceLeadType(): LeadType
    {
        $leadType = LeadType::where('apps_id', $this->lead->app->getId())
            ->where('companies_id', $this->lead->company->getId())
            ->where('name', self::SERVICE_LEAD_TYPE_NAME)
            ->where('is_deleted', 0)
            ->first();

        if ($leadType) {
            return $leadType;
        }

        return new CreateLeadTypeAction(
            new LeadTypeDto(
                apps: $this->lead->app,
                companies: $this->lead->company,
                name: self::SERVICE_LEAD_TYPE_NAME,
                description: 'Service lead type',
                is_active: 1,
            )
        )->execute();
    }

    /**
     * How many handoff notifications this lead is allowed to generate, in total, for its whole
     * lifetime. `0` is a valid kill switch — a company that sets it stops handoff notifications
     * outright while the agent keeps flipping the lead's handoff state.
     */
    protected function maxNotificationsAllowed(): int
    {
        $configured = $this->lead->company->get(self::MAX_NOTIFICATIONS_SETTING)
            ?? $this->app->get(self::MAX_NOTIFICATIONS_SETTING);

        if ($configured === null || ! is_numeric($configured)) {
            return self::DEFAULT_MAX_NOTIFICATIONS;
        }

        return max(0, (int) $configured);
    }

    /**
     * Take the next notification slot, or return null when there is none left to take.
     *
     * `UNIQUE(leads_id, sequence)` is the lock. insertOrIgnore reports how many rows it wrote, so
     * losing the race is a 0 rather than an exception. It walks forward rather than inserting a
     * single sequence because the observed count can lag the truth two ways: a concurrent winner
     * may already hold it, and a legacy lead's first notification has no row to count. The walk is
     * what keeps the ceiling exact in both cases — do not collapse it to one insert.
     */
    protected function claimNotificationSlot(int $notificationsSent, int $maxNotifications, HandOffTypeEnum $handOffType): ?int
    {
        for ($sequence = $notificationsSent + 1; $sequence <= $maxNotifications; $sequence++) {
            $claimed = LeadHandOffNotification::query()->insertOrIgnore([
                'apps_id' => $this->lead->apps_id,
                'companies_id' => $this->lead->companies_id,
                'leads_id' => $this->lead->getKey(),
                'sequence' => $sequence,
                'handoff_type' => $handOffType->value,
                'created_at' => now(),
            ]);

            if ($claimed > 0) {
                return $sequence;
            }
        }

        return null;
    }

    /**
     * Keyed on the lead alone. `$params` must never be part of the dedup identity: it carries the
     * LLM's `conversation_summary`, which is different prose on every call, so any key derived
     * from it lets the agent re-hand-off the same lead indefinitely.
     */
    protected function notificationsSent(): int
    {
        $claims = LeadHandOffNotification::query()
            ->where('leads_id', $this->lead->getKey())
            ->count();

        // Leads handed off before this table existed carry a hashed `handoff_dedup_<md5>` custom
        // field instead, which means at least one notification already went out — so they stay
        // gated without a backfill.
        if ($claims === 0 && $this->hasLegacyDedupMarker()) {
            return 1;
        }

        return $claims;
    }

    protected function hasLegacyDedupMarker(): bool
    {
        return $this->lead->getCustomFieldsQueryBuilder()
            ->where('name', 'like', self::LEGACY_DEDUP_FIELD . '%')
            ->exists();
    }
}
