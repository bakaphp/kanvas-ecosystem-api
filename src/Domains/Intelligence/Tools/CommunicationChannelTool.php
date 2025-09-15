<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Override;

class CommunicationChannelTool implements ContextToolInterface
{
    public function __construct(
        protected Model $entity
    ) {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        return [
            "selected_channel" => $this->entity->get(LeadsEnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value),
            "available_channels" => [
                "email",
                "sms",
                "whatsapp",
            ],
            "selection_reason" => "",
            "first_message" => $this->entity->get(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value) ?? '',
            "contact" => [
                "email" => $this->entity->people->getEmails()->first()->value,
                "phone_e164" => $this->entity->people->getPhones()->first()->value,
            ]
        ];
    }
}
