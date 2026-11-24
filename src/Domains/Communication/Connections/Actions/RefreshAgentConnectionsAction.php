<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Kanvas\Communication\Contracts\ReadsAccountOwnerFromAgents;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Throwable;

/**
 * An agent was given a number, or gave one up. Which agent owns each account is read from the
 * agents by the provider's `accounts()`, so the change only takes effect once the connections of
 * the providers that read that channel are reconciled again.
 *
 * Database only: who holds a number changes nothing at the vendor, so nothing is probed or
 * registered again.
 */
final class RefreshAgentConnectionsAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly ChannelTypeEnum $channel
    ) {
    }

    /**
     * Never throws: the agent is already saved, and the setup sweep reconciles what this missed.
     *
     * @return int how many integrations were reconciled
     */
    public function execute(): int
    {
        $registry = new ProviderRegistry();
        $reconciled = 0;

        // By id: fromCompany() widens to every company of the app on a request made with the app key.
        $integrationCompanies = IntegrationsCompany::query()
            ->where('apps_id', (int) $this->agent->apps_id)
            ->where('companies_id', (int) $this->agent->companies_id)
            ->notDeleted()
            ->with('integration')
            ->get();

        foreach ($integrationCompanies as $integrationCompany) {
            $provider = $registry->forIntegration($integrationCompany);

            if ($provider === null
                || ! is_subclass_of($provider, ReadsAccountOwnerFromAgents::class)
                || ! in_array($this->channel, $provider::agentOwnedChannels(), true)
            ) {
                continue;
            }

            try {
                new EnsureCommunicationSetupAction($this->agent->app, $integrationCompany)->reconcile($provider);
                $reconciled++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $reconciled;
    }
}
