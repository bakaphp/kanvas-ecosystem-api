<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

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

class CreateSocialChannelForContactAction
{
    public function __construct(
        protected readonly Contact $contact,
        protected readonly Apps $app,
        protected readonly array $params,
        protected readonly Lead|People|null $entityOverride = null,
        protected readonly bool $sendPusherNotification = false,
    ) {
    }

    public function execute(): array
    {
        $contactTypesAllowed = [
            ContactTypeEnum::CELLPHONE->value,
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::EMAIL->value,
        ];

        if (! in_array($this->contact->contacts_types_id, $contactTypesAllowed, true)) {
            return [
                'error' => 'Contact type not allowed for social channel creation',
            ];
        }

        $entity = $this->entityOverride ?? LeadsRepository::getPeopleActiveLead($this->contact->people);

        if (! $entity) {
            return [
                'error' => 'No lead associated with this contact',
            ];
        }

        $communicationChannel = match ($this->contact->contacts_types_id) {
            ContactTypeEnum::CELLPHONE->value => 'sms',
            ContactTypeEnum::EMAIL->value => 'email',
            default => 'unknown',
        };

        if ($communicationChannel === 'unknown') {
            return [
                'error' => 'Communication channel could not be determined',
            ];
        }

        $result = $this->createChannelAndSession(
            channelKey: $communicationChannel,
            communicationChannel: $communicationChannel,
            entity: $entity,
            agentId: (int) $this->params['agent_id']
        );

        $channel = $result['channel'];
        $session = $result['session'];
        $isNewChannel = $channel->wasRecentlyCreated;

        // if (! $lead->get(LeadsConfigurationEnum::PREFERRED_CHANNEL->value)) {
        //     $lead->set(LeadsConfigurationEnum::PREFERRED_CHANNEL->value, $communicationChannel);
        // }
        // if (! $lead->get(LeadsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value)) {
        //     $lead->set(LeadsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value, $channel->uuid);
        // }

        if (! empty($this->params['create_whatsapp'])) {
            $whatsappResult = $this->createChannelAndSession(
                channelKey: 'whatsapp',
                communicationChannel: $communicationChannel,
                entity: $entity,
                agentId: (int) $this->params['agent_id']
            );
            $isNewChannel = $isNewChannel || $whatsappResult['channel']->wasRecentlyCreated;
            $channel = $whatsappResult['channel'];
            $session = $whatsappResult['session'];
        }

        $crmNoteResult = null;
        if ($this->entityOverride === null && $entity instanceof Lead && $isNewChannel) {
            $crmNoteResult = new CreateCrmNoteAction($entity, $this->app)->execute();
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
        Lead|People $entity,
        int $agentId
    ): array {
        $contactValue = $this->contact->value;
        if ($communicationChannel === 'sms') {
            $contactValue = Str::normalizePhoneNumber($this->contact->value);
        }

        $channelDto = ChannelDto::from([
            'apps' => $this->app,
            'companies' => $entity->company,
            'users' => $entity->user,
            'entity_id' => $entity->getId(),
            'entity_namespace' => $entity::class,
            'name' => ucwords($communicationChannel) . ' ' . $entity->getId(),
            'slug' => SessionChannelService::createChannelSlug(
                $channelKey,
                $contactValue
            ),
        ]);

        $channel = new CreateChannelAction($channelDto)->execute();

        $sessionDto = Session::from([
            'agent' => Agent::getById($agentId),
            'channel' => $channel,
            'app' => $this->app,
            'company' => $entity->company,
            'entity_id' => $entity->getId(),
            'entity_namespace' => $entity::class,
            'user' => $entity->user->toArray(),
            'canal_id' => SessionChannelService::createCanalId(
                $communicationChannel,
                $contactValue
            ),
        ]);

        $session = new CreateSessionAction($sessionDto)->execute();

        return [
            'channel' => $channel,
            'session' => $session,
        ];
    }
}
