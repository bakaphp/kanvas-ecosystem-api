<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho\Concerns;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\SyncLeadToZohoAction;
use Kanvas\Guild\Leads\Models\Lead;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * SyncLeadToZohoAction with the Zoho module swapped for a mock, so a test asserts on the exact
 * create/update payloads without a Zoho client.
 */
trait StubsZohoLeadModule
{
    protected function action(Lead $lead, ZohoRecordsModule $module): SyncLeadToZohoAction
    {
        return new class (app(Apps::class), $lead, $module) extends SyncLeadToZohoAction {
            public function __construct(AppInterface $app, Lead $lead, private ZohoRecordsModule $module)
            {
                parent::__construct($app, $lead);
            }

            protected function leadModule(Companies $company): ZohoRecordsModule
            {
                return $this->module;
            }
        };
    }
}
