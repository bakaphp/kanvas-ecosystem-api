<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Webhooks;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Companies\Enums\B2BSettingsEnums;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Users\Actions\SendUserNotificationAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class SyncNetSuiteProductWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $barcode = $this->webhookRequest->payload['name'];
        $mainCompanyId = $this->receiver->app->get('B2B_MAIN_COMPANY_ID');
        $productSyncResult = [];

        $successMessage = 'NetSuite Product Not Synced';
        if ($mainCompanyId) {
            $mainCompany = Companies::getById($mainCompanyId);
            $syncNetSuiteProduct = new PullNetSuiteProductPriceAction(
                $this->receiver->app,
                $mainCompany,
                $this->receiver->user
            );
            $productSyncResult = $syncNetSuiteProduct->execute($barcode);
            $successMessage = 'NetSuite Product Synced';

            $app = $this->receiver->app;

            new SendUserNotificationAction(
                $app,
                $mainCompany,
                $this->receiver->user,
                RolesEnums::INVENTORY_MANAGER
            )->execute($app->get(B2BSettingsEnums::B2B_SYNC_INVENTORY_EMAIL_TEMPLATE->getValue()), []);
        }


        return [
            'message' => $successMessage,
            'barcode' => $barcode,
            'mainCompanyId' => $mainCompanyId,
            'productSyncResult' => $productSyncResult,
        ];
    }
}
