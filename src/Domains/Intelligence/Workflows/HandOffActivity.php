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
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\IntegrationsEnum;
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
                if ($lead->get(ConfigurationEnum::AGENT_HAND_OFF->value)) {
                    return ['Handoff was already processed for this lead'];
                }

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

                $handOffType = $params['handoff_type'] ?? 'human';
                $comunicationChannel = $lead->get(EnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value) ?? 'sms';

                if (strtolower($handOffType) === 'compliance_internal') {
                    $contactInfo = match (strtolower($comunicationChannel)) {
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
                }

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

                $leadOwner->notify(
                    $handOffNotification
                );

                //managers
                $managers = UsersRepository::getCompanyAppUserByRole($lead->company, $lead->app, 'Manager')->get();

                foreach ($managers as $manager) {
                    $manager->notify(
                        $handOffNotification
                    );
                }

                $lead->set(ConfigurationEnum::AGENT_HAND_OFF->value, 1);

                return [
                    'success' => true,
                    'message' => 'Handoff processed successfully to ' . $leadOwner->displayname,
                    'manager_notified' => $managers->count(),
                ];
            }
        );
    }
}
