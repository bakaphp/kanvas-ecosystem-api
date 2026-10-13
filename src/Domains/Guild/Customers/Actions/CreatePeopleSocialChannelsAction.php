<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum as SalesAssistConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Throwable;

/**
 * Opens the person's contact channels outside of a lead pull. Without a lead it targets the
 * person's active lead, or their latest one when none is active.
 */
class CreatePeopleSocialChannelsAction
{
    public function __construct(
        protected readonly People $people,
        protected readonly ?Lead $lead = null,
    ) {
    }

    public function execute(): void
    {
        if ($this->people->company === null || ! $this->isEnabled()) {
            return;
        }

        // Runs from model observers, so it must never block the save that triggered it.
        try {
            // Every channel needs an agent for its session, so without one there is nothing to open.
            $defaultAgentId = $this->resolveDefaultAgentId();

            if ($defaultAgentId === null) {
                return;
            }

            $lead = $this->lead
                ?? LeadsRepository::getPeopleActiveLead($this->people)
                ?? LeadsRepository::getPeopleLastLead($this->people);

            if ($lead === null) {
                return;
            }

            new CreateLeadSocialChannelsAction(
                $lead,
                $this->people->app,
                [],
                $this->resolveSessionAgentId($lead) ?? $defaultAgentId,
            )->execute();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The app turns it on for everyone; a company can only opt out.
     */
    private function isEnabled(): bool
    {
        $setting = ConfigurationEnum::CREATE_SOCIAL_CHANNELS_ON_CONTACT_SAVE->value;

        $companyValue = $this->people->company->get($setting);
        $companyOptedOut = $companyValue !== null && ! filter_var($companyValue, FILTER_VALIDATE_BOOL);

        return $this->people->app->getBool($setting) && ! $companyOptedOut;
    }

    /**
     * The same lookup PullLeadActivity opens a lead's channels with.
     */
    private function resolveDefaultAgentId(): ?int
    {
        $agentName = $this->people->company->get(SalesAssistConfigurationEnum::AGENT_NAME->value)
            ?? SalesAssistConfigurationEnum::DEFAULT_AGENT_NAME;

        return Agent::where('name', $agentName)
            ->fromApp($this->people->app)
            ->fromCompany($this->people->company)
            ->notDeleted()
            ->value('id');
    }

    /**
     * Sessions are keyed by agent, so opening the channels with a different agent than the one the
     * lead already talks to would add a second session to each of them.
     */
    private function resolveSessionAgentId(Lead $lead): ?int
    {
        $agentId = Session::query()
            ->whereIn('channel_id', $lead->socialChannels()->pluck('id'))
            ->where('canal_id', 'not like', SessionChannelService::createCanalId('ai-assist', '') . '%')
            ->latest('id')
            ->value('agents_id');

        return $agentId !== null && Agent::where('id', $agentId)->notDeleted()->exists()
            ? (int) $agentId
            : null;
    }
}
