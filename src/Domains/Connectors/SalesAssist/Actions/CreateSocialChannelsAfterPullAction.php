<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;

class CreateSocialChannelsAfterPullAction
{
    public function __construct(
        protected readonly Lead|People $entity,
        protected readonly Apps $app,
        protected readonly array $params,
        protected readonly int $agentId,
    ) {
    }

    public function execute(): void
    {
        if ($this->entity->id === 0) {
            return;
        }

        $people = $this->entity instanceof People ? $this->entity : $this->entity->people;
        $contacts = $people?->contacts;

        if (! $contacts || $contacts->isEmpty()) {
            return;
        }

        $contacts = $contacts->sortBy('contacts_types_id');

        foreach ($contacts as $contact) {
            /**
             * @todo we have to pass the agent not the id
             */
            new CreateSocialChannelForContactAction(
                $contact,
                $this->app,
                array_merge($this->params, ['agent_id' => $this->agentId]),
                $this->entity,
                sendPusherNotification: true
            )->execute();
        }

        CreateAIAssistChannelAction::ifEnabled(
            $this->entity,
            $this->app,
            $this->params,
            $this->agentId
        )?->execute();

        if (! $this->entity->get(LeadsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value)) {
            $defaultChannel = $this->entity->company->get(CompanyConfigurationEnum::DEFAULT_SELECTED_CHANNEL->value)
                ?? $this->app->get(CompanyConfigurationEnum::DEFAULT_SELECTED_CHANNEL->value);

            if ($defaultChannel) {
                $matchedChannel = $this->entity->socialChannels()
                    ->get()
                    ->first(fn ($channel) => str_contains(strtolower($channel->name), strtolower($defaultChannel)));

                if ($matchedChannel) {
                    $this->entity->set(LeadsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value, $matchedChannel->uuid);
                }
            }
        }
    }
}
