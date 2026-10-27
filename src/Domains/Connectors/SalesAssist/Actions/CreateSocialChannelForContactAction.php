<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Services\MetaWhatsAppIntegration;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Actions\Outreach\BootstrapChannelForLeadAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;

class CreateSocialChannelForContactAction
{
    public function __construct(
        protected readonly Contact $contact,
        protected readonly Apps $app,
        protected readonly array $params,
        protected readonly ?Lead $leadOverride = null,
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

        $lead = $this->leadOverride ?? LeadsRepository::getPeopleActiveLead($this->contact->people);

        if (! $lead) {
            return $this->opensWhatsAppChannel()
                ? $this->openWhatsAppConversation()
                : ['error' => 'No lead associated with this contact'];
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
            entity: $lead,
            agentId: (int) $this->params['agent_id']
        );

        $channel = $result['channel'];
        $isNewChannel = $channel->wasRecentlyCreated;

        // if (! $lead->get(LeadsConfigurationEnum::PREFERRED_CHANNEL->value)) {
        //     $lead->set(LeadsConfigurationEnum::PREFERRED_CHANNEL->value, $communicationChannel);
        // }
        // if (! $lead->get(LeadsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value)) {
        //     $lead->set(LeadsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value, $channel->uuid);
        // }

        // A caller asking for it keeps the lead session it always got. A number on the company's
        // WhatsApp integration opens the person's conversation instead.
        if (! empty($this->params['create_whatsapp'])) {
            $whatsappResult = $this->createChannelAndSession(
                channelKey: 'whatsapp',
                communicationChannel: $communicationChannel,
                entity: $lead,
                agentId: (int) $this->params['agent_id']
            );
            $isNewChannel = $isNewChannel || $whatsappResult['channel']->wasRecentlyCreated;
            $channel = $whatsappResult['channel'];
        } elseif ($this->opensWhatsAppChannel()) {
            $whatsappResult = $this->openWhatsAppConversation();
            $isNewChannel = $isNewChannel || $whatsappResult['is_new_channel'];
            $channel = Channel::getById($whatsappResult['channel_id'], $this->app);
        }

        $crmNoteResult = null;
        if ($this->leadOverride === null && $isNewChannel) {
            $crmNoteResult = new CreateCrmNoteAction($lead, $this->app)->execute();
        }

        return [
            'success' => true,
            'channel_id' => $channel->getId(),
            'is_new_channel' => $isNewChannel,
            'crm_note' => $crmNoteResult,
        ];
    }

    private function opensWhatsAppChannel(): bool
    {
        return (int) $this->contact->contacts_types_id === ContactTypeEnum::CELLPHONE->value
            && $this->contact->people !== null
            && MetaWhatsAppIntegration::isActive($this->app, $this->contact->people->company);
    }

    /**
     * A WhatsApp conversation is the person's, lead or no lead, so its channel and session are filed
     * on the People.
     */
    private function openWhatsAppConversation(): array
    {
        $result = $this->createChannelAndSession(
            channelKey: 'whatsapp',
            communicationChannel: 'whatsapp',
            entity: $this->contact->people,
            agentId: (int) $this->params['agent_id']
        );

        return [
            'success' => true,
            'channel_id' => $result['channel']->getId(),
            'is_new_channel' => $result['channel']->wasRecentlyCreated,
            'crm_note' => null,
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

        $agent = Agent::getById($agentId);

        // TODO: Remove Lead channel/session creation when SalesAssist uses the native People session flow.
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
            'agent' => $agent,
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

        // The People conversation already is the person's channel; only a lead's needs one bootstrapped.
        if ($entity instanceof Lead) {
            new BootstrapChannelForLeadAction(
                $entity,
                $communicationChannel,
                $contactValue,
                $agent
            )->execute();
        }

        return [
            'channel' => $channel,
            'session' => $session,
        ];
    }
}
