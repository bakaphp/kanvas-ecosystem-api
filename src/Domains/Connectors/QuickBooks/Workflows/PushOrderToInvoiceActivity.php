<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Workflows;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\QuickBooks\Services\QuickBooksInvoiceService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushOrderToInvoiceActivity extends KanvasActivity
{
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $orderCompany = $order->company;
        $mainAppCompany = B2BConfigurationService::getConfiguredB2BCompany($app, $order->company);

        /**
        * @todo for now we are not allowing to create an invoice for the same company as the B2B main company.
        */
        if ($mainAppCompany->getId() === $orderCompany->getId()) {
            return [
                'result' => false,
                'message' => 'Order company is the same as the B2B main company. No action taken.',
            ];
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::QUICKBOOKS,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $quickBooksInvoice = new QuickBooksInvoiceService($app);

                $quickbooksInvoice = $quickBooksInvoice->createInvoiceFromOrder($order);

                return [
                    'result' => $quickbooksInvoice,
                    'id' => $quickbooksInvoice->Id,
                    'message' => 'Invoice created successfully',
                ];
            },
            company: $mainAppCompany,
        );
    }
}
