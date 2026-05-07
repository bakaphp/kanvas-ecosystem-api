<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerSearchService;

class PullNetSuiteCustomerAction
{
    protected NetSuiteCustomerSearchService $searchService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
        protected UserInterface $user
    ) {
        $this->searchService = new NetSuiteCustomerSearchService($app, $mainAppCompany);
    }

    public function execute(string|int $savedSearchID, ?array $entityIdFilter = null): array
    {
        $customers = $this->searchService->searchWithSavedSearch($savedSearchID);

        if (count($customers) === 0) {
            return [];
        }

        if (empty($customers)) {
            return [
                'company' => $this->mainAppCompany->getId(),
                'app' => $this->app->getId(),
                'error' => 'Customers not found',
                'searchId' => $savedSearchID,
                'savedSearchTotal' => count($customers),
                'processed' => 0,
                'not_found_in_kanvas' => 0,
                'not_found_in_netsuite' => 0,
            ];
        }

        // Index NetSuite customers by internalId for fast lookup
        $netsuiteIndex = [];
        foreach ($customers as $customer) {
            $netsuiteIndex[$customer['internalId']] = $customer;
        }

        $results = [
            "processed" => [],
            "not_found_in_kanvas" => [],
            "not_found_in_netsuite" => [],
            "update_failed" => []
        ];

        // Determine which customers to process
        $customersToProcess = $entityIdFilter ?? array_keys($netsuiteIndex);

        foreach ($customersToProcess as $netsuiteId) {
            // Check if customer exists in NetSuite results
            if (! isset($netsuiteIndex[$netsuiteId])) {
                $results["not_found_in_netsuite"][] = $netsuiteId;
                continue;
            }

            $netsuiteCustomer = $netsuiteIndex[$netsuiteId];

            // Find the People record by NetSuite customer ID
            $linkCompany = Companies::getByCustomField(
                CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value,
                $netsuiteId
            );

            if (! $linkCompany) {
                $results["not_found_in_kanvas"][] = $netsuiteId;
                continue;
            }

            try {
                $companyName = $netsuiteCustomer['entityId'] ?? $netsuiteCustomer['companyName'];
                $linkCompany->name = $companyName;
                $linkCompany->email = $netsuiteCustomer['email'];
                $linkCompany->disableWorkflows();
                $linkCompany->saveOrFail();

                $results["processed"][] = [
                    'netsuiteId' => $netsuiteId,
                    'kanvasId' => $linkCompany->getId(),
                    'name' => $companyName
                ];
            } catch (Exception $e) {
                $results["update_failed"][] = [
                    'netsuiteId' => $netsuiteId,
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'company' => $this->mainAppCompany->getId(),
            'searchId' => $savedSearchID,
            'savedSearchTotal' => count($customers),
            'totalToProcess' => count($customersToProcess),
            'processed' => count($results["processed"]),
            'processedList' => $results["processed"],
            'not_found_in_kanvas' => count($results["not_found_in_kanvas"]),
            'not_found_in_kanvas_list' => $results["not_found_in_kanvas"],
            'not_found_in_netsuite' => count($results["not_found_in_netsuite"]),
            'not_found_in_netsuite_list' => $results["not_found_in_netsuite"],
            'update_failed' => count($results["update_failed"]),
            'update_failed_list' => $results["update_failed"],
        ];
    }
}
