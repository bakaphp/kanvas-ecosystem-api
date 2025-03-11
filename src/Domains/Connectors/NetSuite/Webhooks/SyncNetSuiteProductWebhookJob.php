<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Webhooks;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithCompanyAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class SyncNetSuiteProductWebhookJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $barcode = $this->webhookRequest->payload['name'];
        $mainCompanyId = $this->receiver->app->get('B2B_MAIN_COMPANY_ID');

        if ($mainCompanyId) {
            $mainCompany = Companies::getById($mainCompanyId);
            $syncNetSuiteProduct = new PullNetSuiteProductPriceAction(
                $this->receiver->app,
                $mainCompany
            );
            $syncNetSuiteProduct->execute($barcode);
        }

        return [
            'message' => 'NetSuite Product Synced',
            'barcode' => $barcode,
        ];
    }
}
