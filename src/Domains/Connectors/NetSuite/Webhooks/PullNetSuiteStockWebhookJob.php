<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Webhooks;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Companies\Enums\B2BSettingsEnums;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductStockAction;
use Kanvas\Users\Actions\SendUserNotificationAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'NetSuite Pull Stock Levels',
    description: 'Receiver that refreshes product stock from NetSuite, then emails a summary to the configured '
        . 'recipient. Inbound for the data, outbound for the report — it does mail a person when it '
        . 'finishes.',
    integration: IntegrationsEnum::NETSUITE,
)]
class PullNetSuiteStockWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $mainCompanyId = $this->receiver->app->get('B2B_MAIN_COMPANY_ID');
        $productSyncResult = [];

        $successMessage = 'NetSuite stock not synced';
        if ($mainCompanyId) {
            $mainCompany = Companies::getById($mainCompanyId);
            $syncNetSuiteProduct = new PullNetSuiteProductStockAction(
                $this->receiver->app,
                $mainCompany,
                $this->receiver->user
            );
            $productSyncResult = $syncNetSuiteProduct->execute();
            $successMessage = 'NetSuite stock synced';

            $app = $this->receiver->app;

            new SendUserNotificationAction(
                $app,
                $mainCompany,
                $this->receiver->user,
                RolesEnums::OWNER
            )->execute($app->get(B2BSettingsEnums::B2B_SYNC_INVENTORY_EMAIL_TEMPLATE->getValue()), [
                'results' => $productSyncResult,
            ]);
        }

        return [
            'message' => $successMessage,
            'mainCompanyId' => $mainCompanyId,
            'results' => $productSyncResult,
            'payload' => $payload,
        ];
    }
}
