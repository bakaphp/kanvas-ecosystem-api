<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum as SalesAssistConfigurationEnum;
use Kanvas\Guild\Customers\Actions\CreateLeadSocialChannelsAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Opens the channels of a lead pulled from Zoho, owned by the company's SalesAssist agent or, when no
 * agent carries that name, its first agent. A company with no agent gets no channels.
 */
class CreateZohoLeadChannelsAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly array $params = [],
    ) {
    }

    public function execute(): void
    {
        $agent = $this->agent();

        if ($agent === null) {
            return;
        }

        new CreateLeadSocialChannelsAction(
            $this->lead,
            $this->lead->app,
            $this->params,
            $agent->getId(),
        )->execute();
    }

    protected function agent(): ?Agent
    {
        $app = $this->lead->app;
        $company = $this->lead->company;
        $agentName = $company->get(SalesAssistConfigurationEnum::AGENT_NAME->value)
            ?? SalesAssistConfigurationEnum::DEFAULT_AGENT_NAME;

        return Agent::where('name', $agentName)
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->first() ?? Agent::fromApp($app)->fromCompany($company)->notDeleted()->first();
    }
}
