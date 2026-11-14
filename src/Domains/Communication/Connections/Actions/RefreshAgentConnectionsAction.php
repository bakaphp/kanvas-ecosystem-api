<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Kanvas\Communication\Support\ProviderRollout;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * An agent was given a number or a mailbox, or gave one up. Which accounts a company has, and which
 * agent owns each, is read from the agents by every provider's `accounts()`, so the change only
 * takes effect once the company's messaging integrations are reconciled again.
 */
final class RefreshAgentConnectionsAction
{
    public function __construct(
        private readonly Agent $agent
    ) {
    }

    /**
     * @return list<string> slugs of the providers whose connections were reconciled
     */
    public function execute(): array
    {
        $refreshed = [];

        foreach (new ProviderRollout()->managed($this->agent->app, $this->agent->company) as $provider => $integrationCompany) {
            if (new ScheduleCommunicationSetupAction($this->agent->app, $integrationCompany)->execute()) {
                $refreshed[] = $provider::slug();
            }
        }

        return $refreshed;
    }
}
