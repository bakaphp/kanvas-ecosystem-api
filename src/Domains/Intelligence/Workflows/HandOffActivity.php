<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Notifications\HandOffNotification;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;

class HandOffActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                /*  if ($lead->get(ConfigurationEnum::AGENT_HAND_OFF->value)) {
                     return ['Handoff was already processed for this lead'];
                 } */

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

                $leadOwner = $leadOwner ?? $lead->owner ?? $lead->user;
                $handOffUserRole = $lead->company->get('ai_agent_handoff_user_role') ?? 'Manager';

                $handOffType = strtolower($params['handoff_type'] ?? 'human');
                $communicationChannel = $lead->get(EnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value) ?? 'sms';
                $lead->set(ConfigurationEnum::AGENT_HAND_OFF->value, 1);
                $companyHumanHandOffOnlySms = (bool) ($lead->company->get('ai_human_handoff_only_sms') ?? false);

                $handOffNotification = new HandOffNotification(
                    lead: $lead,
                    templateName: $params['template_name'] ?? 'lead_handoff',
                    data: [
                        'lead' => $lead,
                        'agent' => $leadOwner,
                        'company' => $lead->company,
                        'app' => $lead->app,
                        'user' => $leadOwner,
                        ...$params,
                    ]
                );

                if ($companyHumanHandOffOnlySms && $handOffType === 'human') {
                    $handOffNotification->channels = [
                        TwilioSmsChannel::class,
                    ];
                }

                if ($handOffType === 'compliance_internal') {
                    //$params['template_name'] = 'lead_compliance_handoff';
                    $handOffNotification->setTemplateName('lead_handoff_compliance_handoff');
                    $handOffNotification->setSubject('Lead Compliance Handoff Notification');
                    $handOffNotification->setPushTemplateName('lead_handoff_compliance_push_notification');
                    $handOffNotification->setSmsTemplateName('lead_handoff_compliance_sms_notification');
                    /*  $contactInfo = match (strtolower($communicationChannel)) {
                         'sms' => $lead->people->getCellPhones(),
                         'email' => $leadOwner->getEmails(),
                         default => null
                     };

                     if ($contactInfo === null) {
                         return [
                             'success' => false,
                             'message' => 'No contact info found for the lead or the agent',
                         ];
                     }

                     if ($contactInfo->count()) {
                         foreach ($contactInfo as $contact) {
                             $contact->optOut();
                         }
                     }

                     $lead->people->fireWorkflow(
                         WorkflowEnum::UPDATED->value,
                         true,
                         [
                             'app' => $app,
                         ]
                     );

                     return [
                         'success' => true,
                         'message' => 'Compliance handoff processed successfully , stop all communications , update cms, not handoff email',
                     ]; */
                }

                $leadOwner->notify(
                    $handOffNotification
                );

                //managers
                $managers = UsersRepository::getCompanyAppUserByRole($lead->company, $lead->app, $handOffUserRole)->get();

                foreach ($managers as $manager) {
                    $manager->notify(
                        $handOffNotification
                    );
                }

                return [
                    'success' => true,
                    'message' => 'Handoff processed successfully to ' . $leadOwner->displayname,
                    'manager_notified' => $managers->count(),
                ];
            }
        );
    }
}
