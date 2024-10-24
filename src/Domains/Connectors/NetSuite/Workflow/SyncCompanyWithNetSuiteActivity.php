<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\CustomFieldEnum;
use SyncCompanyWithNetSuiteAction;
use Workflow\Activity;

class SyncCompanyWithNetSuiteActivity extends Activity
{
    public $tries = 3;

    public function execute(Companies $company, Apps $app, array $params): array
    {
        $syncCompanyWithNetSuite = new SyncCompanyWithNetSuiteAction($app, $company);
        $company = $syncCompanyWithNetSuite->execute();

        return [
            'company' => $company->getId(),
            'net_suite_id' => $company->get(CustomFieldEnum::NET_SUITE_COMPANY_ID->value),
            'name' => $company->name,
        ];
    }
}
