<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncCompanyWithNetSuiteAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
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
            'net_suite_id' => $company->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value),
            'name' => $company->name,
        ];
    }
}