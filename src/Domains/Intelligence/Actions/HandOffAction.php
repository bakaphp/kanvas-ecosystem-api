<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Actions;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeDto;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\HandOffTypeEnum;
use Kanvas\Intelligence\Notifications\HandOffNotification;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;

class HandOffAction
{
    private const string DEFAULT_MANAGER_ROLE = 'Manager';
    private const string SERVICE_MANAGER_ROLE = 'ServiceManager';
    private const string DEFAULT_TEMPLATE_NAME = 'lead_handoff';
    private const string SERVICE_LEAD_TYPE_NAME = 'Service';

    public function __construct(
        protected readonly Lead $lead,
        protected readonly AppInterface $app,
        protected readonly array $params = [],
    ) {
    }

    public function execute(): array
    {
        $deduplicationKey = $this->getDeduplicationKey();
        if ($this->isDuplicateNotification($deduplicationKey)) {
            return [
                'success' => true,
                'message' => 'Handoff already processed (duplicate notification prevented)',
                'duplicate' => true,
            ];
        }

        $leadOwner = $this->getLeadOwner();
        $handOffType = HandOffTypeEnum::tryFrom(strtolower((string) ($this->params['handoff_type'] ?? '')))
            ?? HandOffTypeEnum::HUMAN;
        $handOffUserRole = $this->getHandOffUserRole($handOffType);

        $leadOwner = $this->applyRotation($leadOwner);

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

        $this->markNotificationAsProcessed($deduplicationKey);

        return [
            'success' => true,
            'message' => 'Handoff processed successfully to ' . $leadOwner->displayname,
            'manager_notified' => $managersNotified,
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

    protected function applyRotation(Users $leadOwner): Users
    {
        if (! empty($this->params['rotation_id'])) {
            try {
                $rotation = LeadRotation::getById($this->params['rotation_id'], $this->app);
                if ($rotation && $agent = $rotation->getAgent()) {
                    $this->lead->leads_owner_id = $agent->getId();
                    $this->lead->saveOrFail();

                    return $agent;
                }
            } catch (Exception) {
            }
        }

        return $leadOwner;
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
            : ($this->lead->company->get('ai_agent_handoff_user_role') ?? self::DEFAULT_MANAGER_ROLE);
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
        $companyHumanHandOffOnlySms = (bool) $this->lead->company->get('ai_human_handoff_only_sms');
        $companyHumanHandOffOnlyMail = (bool) $this->lead->company->get('ai_human_handoff_only_mail');
        $companyComplianceHandOffOnlyPush = (bool) $this->lead->company->get('ai_compliance_handoff_only_push');

        if ($companyHumanHandOffOnlySms && $handOffType === HandOffTypeEnum::HUMAN) {
            $notification->channels = [TwilioSmsChannel::class];
        }

        if ($companyHumanHandOffOnlyMail && $handOffType === HandOffTypeEnum::HUMAN) {
            $notification->channels = ['mail'];
        }

        if ($handOffType === HandOffTypeEnum::COMPLIANCE_INTERNAL) {
            $notification->setTemplateName('lead_handoff_compliance_handoff');
            $notification->setSubject('Lead Compliance Handoff Notification - ' . $this->lead->people->name);
            $notification->setPushTemplateName('lead_handoff_compliance_push_notification');
            $notification->setSmsTemplateName('lead_handoff_compliance_sms_notification');
            $notification->setDatabaseTemplateName('lead_handoff_compliance_sms_notification');

            if ($companyComplianceHandOffOnlyPush) {
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
        try {
            $managers = UsersRepository::getCompanyAppUserByRole(
                $this->lead->company,
                $this->lead->app,
                $handOffUserRole,
            )->get();
        } catch (Exception) {
            return 0;
        }

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

    protected function getDeduplicationKey(): string
    {
        $dataToHash = [
            'lead_id' => $this->lead->getId(),
            'params' => $this->params,
        ];

        return md5((string) json_encode($dataToHash));
    }

    protected function isDuplicateNotification(string $deduplicationKey): bool
    {
        return $this->lead->get('handoff_dedup_' . $deduplicationKey) !== null;
    }

    protected function markNotificationAsProcessed(string $deduplicationKey): void
    {
        $this->lead->set('handoff_dedup_' . $deduplicationKey, time());
    }
}
