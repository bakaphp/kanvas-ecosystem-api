<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Webhooks;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerItemsListAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithCompanyAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ProcessNetSuiteCompanyCustomerWebhookJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        //$regionId = $this->receiver->configuration['region_id'];
        $isCompany = ! empty($this->webhookRequest->payload['fields']['companyname']);
        $netSuiteCompanyId = $this->webhookRequest->payload['id'];

        if (! $isCompany) {
            return [
                'message' => 'Not a NetSuite Company',
            ];
        }

        $syncCompanyWithNetSuite = new SyncNetSuiteCustomerWithCompanyAction($this->receiver->app, $this->receiver->company);
        $company = $syncCompanyWithNetSuite->execute($netSuiteCompanyId);

        //update or create customer own channel price list
        $mainCompanyId = $this->receiver->app->get('B2B_MAIN_COMPANY_ID');

        if ($isCompany) {
            $mainCompany = Companies::getById($mainCompanyId);

            $syncNetSuiteCustomerWithCompany = new SyncNetSuiteCustomerItemsListAction(
                $this->receiver->app,
                $mainCompany,
                $company
            );
            $syncNetSuiteCustomerWithCompany->execute();
        }

        return [
            'message' => 'NetSuite Company Synced',
            'netSuiteCompanyId' => $netSuiteCompanyId,
        ];
    }
}
