<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Notifications\HandOffNotification;
use Kanvas\Intelligence\Services\HandOffNoteService;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;

class HandOffActivity extends KanvasActivity
{
    public $tries = 3;

    private const string DEFAULT_HANDOFF_TYPE = 'human';
    private const string DEFAULT_MANAGER_ROLE = 'Manager';
    private const string SERVICE_MANAGER_ROLE = 'ServiceManager';
    private const string DEFAULT_TEMPLATE_NAME = 'lead_handoff';

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $leadOwner = $this->getLeadOwner($lead, $app, $params);
                $handOffType = strtolower($params['handoff_type'] ?? self::DEFAULT_HANDOFF_TYPE);
                $handOffUserRole = $this->getHandOffUserRole($lead, $handOffType);

                if (! empty($params['rotation_id'])) {
                    try {
                        $rotation = LeadRotation::getById($params['rotation_id'], $app);
                        if ($rotation && $agent = $rotation->getAgent()) {
                            $lead->leads_owner_id = $agent->getId();
                            $lead->saveOrFail();
                            $leadOwner = $agent;
                        }
                    } catch (Exception $e) {
                    }
                }

                if ($handOffType == 'human') {
                    $lead->fireWorkflow(
                        WorkflowEnum::TRIGGER_AI->value,
                        true,
                        [
                            'app' => $app,
                            'trigger_type' => TriggersEnum::HUMAN_HANDOFF->value,
                        ]
                    );
                }
                //$communicationChannel = $lead->get(EnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value) ?? 'sms';
                $lead->set(ConfigurationEnum::AGENT_HAND_OFF->value, 1);

                $handOffNotification = $this->createHandOffNotification($lead, $leadOwner, $handOffType, $params);
                $leadOwner->notify($handOffNotification);

                $this->postConversationSummary($lead, $leadOwner, $handOffType, $params);
                $managersNotified = $this->notifyManagers($lead, $leadOwner, $handOffNotification, $handOffUserRole);

                return [
                    'success' => true,
                    'message' => 'Handoff processed successfully to ' . $leadOwner->displayname,
                    'manager_notified' => $managersNotified,
                ];
            }
        );
    }

    private function getLeadOwner(Lead $lead, Apps $app, array $params): Users
    {
        if (! empty($params['rotation_id'])) {
            try {
                $rotation = LeadRotation::getById($params['rotation_id'], $app);
                $agent = $rotation->getAgent();
                if ($agent) {
                    $lead->leads_owner_id = $agent->getId();
                    $lead->saveOrFail();

                    return $agent;
                }
            } catch (Exception) {
            }
        }

        return $lead->owner ?? $lead->user;
    }

    private function getHandOffUserRole(Lead $lead, string $handOffType): string
    {
        return $handOffType === 'service'
            ? self::SERVICE_MANAGER_ROLE
            : ($lead->company->get('ai_agent_handoff_user_role') ?? self::DEFAULT_MANAGER_ROLE);
    }

    private function createHandOffNotification(
        Lead $lead,
        Users $leadOwner,
        string $handOffType,
        array $params
    ): HandOffNotification {
        $notification = new HandOffNotification(
            lead: $lead,
            templateName: $params['template_name'] ?? self::DEFAULT_TEMPLATE_NAME,
            data: [
                'lead' => $lead,
                'agent' => $leadOwner,
                'company' => $lead->company,
                'app' => $lead->app,
                'user' => $leadOwner,
                ...$params,
            ]
        );

        $this->configureNotificationChannels($notification, $lead, $handOffType);

        return $notification;
    }

    private function configureNotificationChannels(
        HandOffNotification $notification,
        Lead $lead,
        string $handOffType
    ): void {
        $companyHumanHandOffOnlySms = (bool) $lead->company->get('ai_human_handoff_only_sms');
        $companyComplianceHandOffOnlyPush = (bool) $lead->company->get('ai_compliance_handoff_only_push');

        if ($companyHumanHandOffOnlySms && $handOffType === 'human') {
            $notification->channels = [TwilioSmsChannel::class];
        }

        if ($handOffType === 'compliance_internal') {
            $notification->setTemplateName('lead_handoff_compliance_handoff');
            $notification->setSubject('Lead Compliance Handoff Notification - ' . $lead->people->name);
            $notification->setPushTemplateName('lead_handoff_compliance_push_notification');
            $notification->setSmsTemplateName('lead_handoff_compliance_sms_notification');

            if ($companyComplianceHandOffOnlyPush) {
                $notification->setChannelOnlyPush();
            }
        }
    }

    private function postConversationSummary(
        Lead $lead,
        Users $leadOwner,
        string $handOffType,
        array $params
    ): void {
        $conversationSummary = $params['conversation_summary'] ?? '';
        if ($conversationSummary !== '') {
            $handOffNoteService = new HandOffNoteService($lead, $leadOwner);
            $handOffNoteService->postHandOffNote($conversationSummary, $handOffType);
        }
    }

    private function notifyManagers(
        Lead $lead,
        Users $leadOwner,
        HandOffNotification $notification,
        string $handOffUserRole
    ): int {
        $managers = UsersRepository::getCompanyAppUserByRole(
            $lead->company,
            $lead->app,
            $handOffUserRole
        )->get();

        $notifiedCount = 0;
        foreach ($managers as $manager) {
            if ($leadOwner->getId() !== $manager->getId()) {
                $manager->notify($notification);
                $notifiedCount++;
            }
        }

        return $notifiedCount;
    }
}
