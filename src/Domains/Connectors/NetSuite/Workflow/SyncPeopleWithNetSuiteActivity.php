<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\NetSuite\Actions\SyncPeopleWithNetSuiteAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Workflow\Activity;

class SyncPeopleWithNetSuiteActivity extends Activity
{
    public $tries = 3;

    public function execute(People $people, Apps $app, array $params): array
    {
        $syncPeopleWithNetSuite = new SyncPeopleWithNetSuiteAction($app, $people->company);
        $company = $syncPeopleWithNetSuite->execute();

        return [
            'people' => $people->getId(),
            'net_suite_id' => $people->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value),
            'name' => $people->name,
        ];
    }
}
