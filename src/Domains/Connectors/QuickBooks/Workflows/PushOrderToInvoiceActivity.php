<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Workflows;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\QuickBooks\Services\QuickBooksInvoiceService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushOrderToInvoiceActivity extends KanvasActivity
{
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::RECOMBEE,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $quickBooksInvoice = new QuickBooksInvoiceService($app);

                $quickbooksInvoice = $quickBooksInvoice->createInvoiceFromOrder($order);

                return [
                    'result' => $quickbooksInvoice,
                    'id' => $quickbooksInvoice->Id,
                    'message' => 'Invoice created successfully',
                ];
            },
            company: $order->company,
        );
    }
}
