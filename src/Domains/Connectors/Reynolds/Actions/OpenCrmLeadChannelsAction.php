<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Kanvas\Connectors\Reynolds\DataTransferObject\CrmLeadSync;
use Kanvas\Connectors\Reynolds\Enums\CrmLeadSyncSettingEnum;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum as SalesAssistConfigurationEnum;
use Kanvas\Guild\Customers\Actions\CreateLeadSocialChannelsAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Throwable;

/**
 * Opens the sms and email channels of the lead's contacts as part of the sync, so they exist when it
 * answers instead of whenever an observer gets to them. Off unless the company, or else the app, sets
 * `crm_sync_create_social_channels` to true, 1 or 'true'; it does not look at
 * guild_create_social_channels_on_contact_save. Opening them twice is harmless: a channel is found by
 * its slug before one is made.
 *
 * The data is saved by now, so a failure here is reported and told to the caller, never thrown.
 */
class OpenCrmLeadChannelsAction
{
    public function __construct(
        protected readonly CrmLeadSync $data,
        protected readonly Lead $lead
    ) {
    }

    /**
     * @return list<array{code: string, field: string}> warnings
     */
    public function execute(): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        try {
            $agentId = $this->sessionAgentId() ?? $this->defaultAgentId();

            if ($agentId === null) {
                return [['code' => 'social_channels_skipped', 'field' => 'agent']];
            }

            // The contacts were added in this request, after the lead may have loaded its people.
            $this->lead->load('people.contacts');

            new CreateLeadSocialChannelsAction(
                $this->lead,
                $this->lead->app,
                [],
                $agentId
            )->execute();
        } catch (Throwable $exception) {
            report($exception);

            return [['code' => 'social_channels_failed', 'field' => 'customer.contacts']];
        }

        return [];
    }

    private function isEnabled(): bool
    {
        $setting = CrmLeadSyncSettingEnum::CREATE_SOCIAL_CHANNELS->value;

        return filter_var(
            $this->data->company->get($setting) ?? $this->data->app->get($setting),
            FILTER_VALIDATE_BOOL
        );
    }

    /**
     * Sessions are keyed by agent: opening the channels with another agent than the one the lead already
     * talks to would add a second session to each. The same lookup CreatePeopleSocialChannelsAction does.
     */
    private function sessionAgentId(): ?int
    {
        $agentId = $this->lead->aiSession()
            ->where('canal_id', 'not like', SessionChannelService::createCanalId('ai-assist', '') . '%')
            ->latest('id')
            ->value('agents_id');

        return $agentId !== null && Agent::where('id', $agentId)->notDeleted()->exists()
            ? (int) $agentId
            : null;
    }

    /**
     * The same lookup PullLeadActivity opens a lead's channels with.
     */
    private function defaultAgentId(): ?int
    {
        $agentName = $this->data->company->get(SalesAssistConfigurationEnum::AGENT_NAME->value)
            ?? SalesAssistConfigurationEnum::DEFAULT_AGENT_NAME;

        $agentId = Agent::where('name', $agentName)
            ->fromApp($this->data->app)
            ->fromCompany($this->data->company)
            ->notDeleted()
            ->value('id');

        return $agentId === null ? null : (int) $agentId;
    }
}
