<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncCompanyWithNetSuiteAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerItemsListAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Workflow\KanvasActivities;

class SyncCompanyWithNetSuiteActivity extends KanvasActivities
{
    public function execute(Companies $company, Apps $app, array $params): array
    {
        $syncCompanyWithNetSuite = new SyncCompanyWithNetSuiteAction($app, $company);
        $company = $syncCompanyWithNetSuite->execute();

        //update or create customer own channel price list
        $mainCompanyId = $app->get('B2B_MAIN_COMPANY_ID');

        if ($mainCompanyId) {
            $mainCompany = Companies::getById($mainCompanyId);

            $syncNetSuiteCustomerWithCompany = new SyncNetSuiteCustomerItemsListAction($app, $mainCompany, $company);
            $syncNetSuiteCustomerWithCompany->execute();
        }

        return [
            'company' => $company->getId(),
            'net_suite_id' => $company->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value),
            'name' => $company->name,
        ];
    }
}
