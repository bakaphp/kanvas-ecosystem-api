<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;

class IndexLeadsBySearch
{
    public function __construct(
        AppInterface $app,
        Companies $company
    ) {
    }

    public function execute(string $searchText): array
    {
        /*  $eLeadCustomer = new Customer();
         $eLeadCustomer->company = $this->company;
         $customers = $eLeadCustomer->search([
             'phoneNumber' => $searchText,
             'emailAddress' => $searchText,
             'firstName' => $searchText,
             'lastName' => $searchText,
         ]);

         $results = [];
         if ($customers && isset($customers['items'])) {
             foreach ($customers['items'] as $customer) {
                 try {
                     $eLead = Lead::getByCustomerId($this->company, $customer['id']);
                     $eLead->customerId = $customer['id'];
                     $syncLeadByCustomFieldAction = new SyncLeadByCustomFieldAction($this->company);
                     $results[] = $syncLeadByCustomFieldAction->execute(LeadData::createFromLead($eLead));
                 } catch (Throwable $e) {
                     continue;
                 }
             }
         }

         return $results; */

        return [];
    }
}
