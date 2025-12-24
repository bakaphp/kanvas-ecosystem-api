<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Workflows;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\QuickBooks\Services\QuickBooksDepositService;
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

        $mainAppCompany = B2BConfigurationService::getConfiguredB2BCompany($app, $order->company);

        sleep(100); // Simulate a delay for the integration process

        $order->refresh();
        $orderCompany = $order->company;

        $hasTag = $order->hasTag(['generate_quickbooks_invoice']);
        $validateB2B = (bool) ($params['validate_b2b'] ?? true);

        /**
        * @todo for now we are not allowing to create an invoice for the same company as the B2B main company.
        */
        if ($validateB2B && $mainAppCompany->getId() === $orderCompany->getId()) {
            return [
                'result' => false,
                'message' => 'Order company is the same as the B2B main company. No action taken.',
            ];
        } elseif (! $hasTag) {
            return [
                'result' => false,
                'message' => 'Order does not have the required tag to generate QuickBooks invoice. No action taken.',
            ];
        }

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::QUICKBOOKS,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                $quickBooksInvoice = new QuickBooksInvoiceService($app);
                $quickBookDeposit = new QuickBooksDepositService($app);

                /**
                 * @todo Check if the order contains a valid product type.
                 * This is a temporary solution to ensure that the order contains a valid product type.
                 */
                $isCreditProductType = false;
                foreach ($order->items as $item) {
                    if (in_array($item->variant->product->products_types_id, $params['credit_product_type'])) {
                        $isCreditProductType = true;

                        break;
                    }
                }

                $quickbooksInvoice = $isCreditProductType ? $quickBookDeposit->createDepositFromCreditOrder($order) : $quickBooksInvoice->createInvoiceFromOrder($order);

                return [
                    'result' => $quickbooksInvoice,
                    'id' => $quickbooksInvoice->Id,
                    'type' => $isCreditProductType ? 'deposit' : 'invoice',
                    'message' => 'Invoice created successfully',
                ];
            },
            company: $mainAppCompany,
        );
    }
}
