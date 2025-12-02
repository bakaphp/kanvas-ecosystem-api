<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Actions\CreateAffiliateConversionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CreateAffiliateConversionActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function (Order $order) {
                // Check if order has affiliate information
                $metadata = is_array($order->metadata) ? $order->metadata : [];
                $affiliateId = $order->get('affiliate_id') ?? $metadata['affiliate_id'] ?? null;

                if ($affiliateId === null) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'Order does not have affiliate_id in custom_fields or metadata',
                        'order_id' => $order->getId(),
                    ]);
                }

                $action = new CreateAffiliateConversionAction($order);
                $conversion = $action->execute();

                return [
                    'result' => true,
                    'message' => 'Affiliate conversion created successfully',
                    'order_id' => $order->getId(),
                    'conversion_id' => $conversion->getId(),
                    'affiliate_id' => $conversion->affiliates_id,
                    'commission_amount' => $conversion->commission_amount,
                    'commission_rate' => $conversion->commission_rate,
                    'commission_type' => $conversion->commission_type,
                    'status' => $conversion->status,
                ];
            },
            company: $order->company,
        );
    }
}
