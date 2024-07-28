<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Generator;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Guild\Leads\Models\LeadReceiver;

class DownloadAllZohoLeadAction
{
    protected int $totalLeadsProcessed = 0;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected LeadReceiver $receiver,
    ) {
    }

    public function execute($totalPages = 50, $leadsPerPage = 200): Generator
    {
        $zohoClient = Client::getInstance($this->app, $this->company);

        for ($page = 1; $page <= $totalPages; $page++) {
            $leads = $zohoClient->leads->getList(['page' => $page, 'per_page' => $leadsPerPage]);
            foreach ($leads as $lead) {
                $syncZohoLead = new SyncZohoLeadAction(
                    $this->app,
                    $this->company,
                    $this->receiver,
                    $lead->getId()
                );

                $localLead = $syncZohoLead->execute($lead);

                if (! $localLead) {
                    continue;
                }

                $this->totalLeadsProcessed++;
                yield $localLead;
            }
        }
    }

    public function getTotalLeadsProcessed(): int
    {
        return $this->totalLeadsProcessed;
    }
}
