<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;

class CreateSocialChannelsAfterPullAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly Apps $app,
        protected readonly array $params,
        protected readonly int $agentId,
    ) {
    }

    public function execute(): void
    {
        if ($this->lead->id === 0) {
            return;
        }

        $contacts = $this->lead->people?->contacts;

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
                $this->lead,
                sendPusherNotification: true
            )->execute();
        }

        $aiAssistEnabled = (bool) ($this->lead->company->get(ConfigurationEnum::AI_ASSIST_ENABLED->value)
            ?? $this->app->get(ConfigurationEnum::AI_ASSIST_ENABLED->value)
            ?? false);

        if ($aiAssistEnabled) {
            new CreateAIAssistChannelAction(
                $this->lead,
                $this->app,
                $params['ai_assist_agent_id'] ?? $this->agentId,
            )->execute();
        }

        if (! $this->lead->get(LeadsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value)) {
            $defaultChannel = $this->lead->company->get(CompanyConfigurationEnum::DEFAULT_SELECTED_CHANNEL->value)
                ?? $this->app->get(CompanyConfigurationEnum::DEFAULT_SELECTED_CHANNEL->value);

            if ($defaultChannel) {
                $matchedChannel = $this->lead->socialChannels()
                    ->get()
                    ->first(fn ($channel) => str_contains(strtolower($channel->name), strtolower($defaultChannel)));

                if ($matchedChannel) {
                    $this->lead->set(LeadsConfigurationEnum::GUILD_PREFERRED_CHANNEL_UUID->value, $matchedChannel->uuid);
                }
            }
        }
    }
}
