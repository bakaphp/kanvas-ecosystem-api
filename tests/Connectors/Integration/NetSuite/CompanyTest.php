<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncCompanyWithNetSuiteAction;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Tests\TestCase;

final class CompanyTest extends TestCase
{
    public function testSynCompanyWithNetSuite()
    {
        $company = Companies::first();
        $app = app(Apps::class);

        //$netSuiteService = new Client($app, $company);
        //@todo use netsuite sandbox

        $syncCompany = new SyncCompanyWithNetSuiteAction($app, $company);
        //$result = $syncCompany->execute();

        //$this->assertTrue($result->get(CustomFieldEnum::NET_SUITE_COMPANY_ID->value) > 0);
    }
}
