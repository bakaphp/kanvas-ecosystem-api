<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Generator;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Zoho\Client;

class DownloadAllZohoAgentAction
{
    protected int $totalAgentsProcessed = 0;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected string $agentModule = 'Agents' // Default agent module name
    ) {
    }

    public function execute(int $totalPages = 50, int $agentsPerPage = 200): Generator
    {
        $zohoClient = Client::getInstance($this->app, $this->company);

        for ($page = 1; $page <= $totalPages; $page++) {
            // Determine the module to fetch agents from
            $module = $this->agentModule === 'Agents' ? 'agents' : 'vendors';
            $agents = $zohoClient->{$module}->getList(['page' => $page, 'per_page' => $agentsPerPage]);

            foreach ($agents as $agent) {
                $email = $agent->Email; // Assuming the agent record has an 'Email' field

                if (! $email) {
                    continue; // Skip agents without an email
                }

                // Process individual agent
                try {
                    $syncZohoAgent = new SyncZohoAgentAction(
                        $this->app,
                        $this->company,
                        $email
                    );

                    $localAgent = $syncZohoAgent->execute();
                } catch (Exception $e) {
                    Log::error('Error syncing Zoho agent: ' . $e->getMessage());

                    continue;
                }

                if (! $localAgent) {
                    continue;
                }

                $this->totalAgentsProcessed++;
                yield $localAgent;
            }
        }
    }

    public function getTotalAgentsProcessed(): int
    {
        return $this->totalAgentsProcessed;
    }
}
