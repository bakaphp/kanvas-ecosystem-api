<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Guild\Leads\Models\LeadReceiver;

class DownloadAllZohoLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected LeadReceiver $receiver,
    ) {
    }

    public function execute($totalPages = 50, $leadsPerPage = 200): array
    {
        $zohoClient = Client::getInstance($this->app, $this->company);

        $localLeadsIds = [];

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
                $localLeadsIds[] = $localLead->getId();
            }
        }

        return $localLeadsIds;
    }
}
