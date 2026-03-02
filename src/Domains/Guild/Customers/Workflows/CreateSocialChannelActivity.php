<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Workflows;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CreateSocialChannelActivity extends KanvasActivity
{
    public function execute(Contact|Lead $entity, Apps $app, array $params): array
    {
        if (empty($params['agent_id'])) {
            return [
                'error' => 'Agent ID is required to create social channel',
            ];
        }

        if ($entity instanceof Lead) {
            return $this->executeForLead($entity, $app, $params);
        }

        return $this->executeForContact($entity, $app, $params);
    }

    private function executeForLead(Lead $lead, Apps $app, array $params): array
    {
        $results = [];
        $people = $lead->people;

        if (! $people instanceof People) {
            return [
                'error' => 'No people associated with this lead',
            ];
        }

        $contacts = $people->contacts;

        if ($contacts->isEmpty()) {
            return [
                'error' => 'No contacts found for this lead',
            ];
        }

        foreach ($contacts as $contact) {
            $result = $this->executeForContact($contact, $app, $params, $lead);
            $results[] = $result;
        }

        return [
            'success' => true,
            'results' => $results,
        ];
    }

    private function executeForContact(Contact $contact, Apps $app, array $params, ?Lead $leadOverride = null): array
    {
        $contactTypesAllowed = [
            ContactTypeEnum::CELLPHONE->value,
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::EMAIL->value,
        ];

        if (! in_array($contact->contacts_types_id, $contactTypesAllowed, true)) {
            return [
                'error' => 'Contact type not allowed for social channel creation',
            ];
        }

        $company = $contact->people->company;

        return $this->executeIntegration(
            entity: $contact,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($contact, $app, $integrationCompany, $additionalParams) use ($params, $leadOverride): array {
                $lead = $leadOverride ?? LeadsRepository::getPeopleActiveLead($contact->people);

                if (! $lead) {
                    return $this->failWorkflow([
                        'error' => 'No lead associated with this contact',
                    ]);
                }

                $communicationChannel = match ($contact->contacts_types_id) {
                    ContactTypeEnum::CELLPHONE->value => 'sms',
                    ContactTypeEnum::EMAIL->value => 'email',
                    default => 'unknown',
                };

                if ($communicationChannel === 'unknown') {
                    return $this->failWorkflow([
                        'error' => 'Communication channel could not be determined',
                    ]);
                }

                $channel = $this->createChannelAndSession(
                    channelKey: $communicationChannel,
                    communicationChannel: $communicationChannel,
                    contact: $contact,
                    app: $app,
                    lead: $lead,
                    agentId: (int) $params['agent_id']
                );

                // Set preferred channel to the first channel created for this lead
                if (! $lead->get(LeadsConfigurationEnum::PREFERRED_CHANNEL->value)) {
                    $lead->set(LeadsConfigurationEnum::PREFERRED_CHANNEL->value, $communicationChannel);
                }

                if (! empty($params['create_whatsapp'])) {
                    $channel = $this->createChannelAndSession(
                        channelKey: 'whatsapp',//slug
                        communicationChannel: $communicationChannel,
                        contact: $contact,
                        app: $app,
                        lead: $lead,
                        agentId: (int) $params['agent_id']
                    );
                }

                return [
                    'success' => true,
                    'channel_id' => $channel->getId(),
                ];
            },
            company: $company
        );
    }

    private function createChannelAndSession(
        string $channelKey,
        string $communicationChannel,
        Contact $contact,
        Apps $app,
        Lead $lead,
        int $agentId
    ): Channel {
        $contactValue = $contact->value;
        if ($communicationChannel === 'sms') {
            $contactValue = Str::normalizePhoneNumber($contact->value);
        }

        $channelDto = ChannelDto::from([
            'apps' => $app,
            'companies' => $lead->company,
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => ucwords($communicationChannel) . ' ' . $lead->getId(),
            'slug' => SessionChannelService::createChannelSlug(
                $channelKey,
                $contactValue
            ),
        ]);

        $channel = new CreateChannelAction($channelDto)->execute();

        $sessionDto = Session::from([
            'agent' => Agent::getById($agentId),
            'channel' => $channel,
            'app' => $app,
            'company' => $lead->company,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'user' => $lead->user->toArray(),
            'canal_id' => SessionChannelService::createCanalId(
                $communicationChannel,
                $contactValue
            ),
        ]);

        new CreateSessionAction($sessionDto)->execute();

        return $channel;
    }
}
