<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Workflow\KanvasActivity;

class CreateSocialChannelActivity extends KanvasActivity
{
    public function execute(Contact $contact, Apps $app, array $params): array
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

        $lead = $contact->people->leads->first();

        $communicationChannel = match ($contact->contacts_types_id) {
            ContactTypeEnum::CELLPHONE->value => 'sms',
            ContactTypeEnum::PHONE->value => 'sms',
            ContactTypeEnum::EMAIL->value => 'email',
            default => 'unknown',
        };

        $channel = $this->createChannelAndSession(
            channelKey: $communicationChannel,
            communicationChannel: $communicationChannel,
            contact: $contact,
            app: $app,
            lead: $lead,
            agentId: (int) $params['agent_id']
        );

        if ($communicationChannel === 'sms') {
            $channel = $this->createChannelAndSession(
                channelKey: 'whatsapp',          //slug
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
    }

    private function createChannelAndSession(
        string $channelKey,
        string $communicationChannel,
        Contact $contact,
        Apps $app,
        Lead $lead,
        int $agentId
    ): Channel {
        $channelDto = ChannelDto::from([
            'apps' => $app,
            'companies' => $lead->company,
            'users' => $lead->user,
            'entity_id' => $lead->getId(),
            'entity_namespace' => Lead::class,
            'name' => ucwords($communicationChannel) . ' ' . $lead->getId(),
            'slug' => SessionChannelService::createChannelSlug(
                $channelKey,
                $contact->value
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
                $contact->value
            ),
        ]);

        new CreateSessionAction($sessionDto)->execute();

        return $channel;
    }
}
