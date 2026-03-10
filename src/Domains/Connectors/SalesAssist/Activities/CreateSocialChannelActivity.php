<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\CreateCrmNoteAction;
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

        $company = $entity instanceof Lead ? $entity->company : $entity->people->company;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($entity, $app, $params): array {
                if ($entity instanceof Lead) {
                    return $this->executeForLead($entity, $app, $params);
                }

                return $this->executeForContact($entity, $app, $params);
            },
            company: $company
        );
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

        $hasNewChannel = false;
        foreach ($contacts as $contact) {
            $result = $this->executeForContact($contact, $app, $params, $lead);
            $results[] = $result;

            // Track if any channel was newly created
            if (! empty($result['is_new_channel'])) {
                $hasNewChannel = true;
            }
        }

        // Create note in CRM only if a new channel was created
        $crmNoteResult = $hasNewChannel
            ? new CreateCrmNoteAction($lead, $app)->execute()
            : null;

        return [
            'success' => true,
            'results' => $results,
            'crm_note' => $crmNoteResult,
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

        $lead = $leadOverride ?? LeadsRepository::getPeopleActiveLead($contact->people);

        if (! $lead) {
            return [
                'error' => 'No lead associated with this contact',
            ];
        }

        $communicationChannel = match ($contact->contacts_types_id) {
            ContactTypeEnum::CELLPHONE->value => 'sms',
            ContactTypeEnum::EMAIL->value => 'email',
            default => 'unknown',
        };

        if ($communicationChannel === 'unknown') {
            return [
                'error' => 'Communication channel could not be determined',
            ];
        }

        $channel = $this->createChannelAndSession(
            channelKey: $communicationChannel,
            communicationChannel: $communicationChannel,
            contact: $contact,
            app: $app,
            lead: $lead,
            agentId: (int) $params['agent_id']
        );

        // Track if channel was newly created
        $isNewChannel = $channel->wasRecentlyCreated;

        // Set preferred channel to the first channel created for this lead
        if (! $lead->get(LeadsConfigurationEnum::PREFERRED_CHANNEL->value)) {
            $lead->set(LeadsConfigurationEnum::PREFERRED_CHANNEL->value, $communicationChannel);
        }

        if (! empty($params['create_whatsapp'])) {
            $whatsappChannel = $this->createChannelAndSession(
                channelKey: 'whatsapp',
                communicationChannel: $communicationChannel,
                contact: $contact,
                app: $app,
                lead: $lead,
                agentId: (int) $params['agent_id']
            );
            $isNewChannel = $isNewChannel || $whatsappChannel->wasRecentlyCreated;
            $channel = $whatsappChannel;
        }

        // Create note in CRM only if a new channel was created (when called directly for Contact)
        $crmNoteResult = null;
        if ($leadOverride === null && $isNewChannel) {
            $crmNoteResult = new CreateCrmNoteAction($lead, $app)->execute();
        }

        return [
            'success' => true,
            'channel_id' => $channel->getId(),
            'is_new_channel' => $isNewChannel,
            'crm_note' => $crmNoteResult,
        ];
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
