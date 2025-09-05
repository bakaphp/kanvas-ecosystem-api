<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PromptIAPOrderActivity extends KanvasActivity
{
    public $tries = 2;

    public function execute(Order $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::PROMPT_MINE,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $order->refresh();

                //add user credit
                $orderCredit = $order->user->get('order_credits', []);
                $orderCredit['video'] ??= [];

                foreach ($order->items as $item) {
                    $variant = $item->variant;
                    if (strtolower($variant->product->type->name) !== 'pickamodel') {
                        continue;
                    }

                    $videoKey = $variant->getAttributeBySlug('ai-model')?->value;

                    if (empty($videoKey)) {
                        continue;
                    }

                    if (isset($orderCredit['video'][$videoKey])) {
                        $orderCredit['video'][$videoKey]++;
                    } else {
                        $orderCredit['video'][$videoKey] = 1;
                    }
                }

                $order->user->set('order_credits', $orderCredit, true);

                return [
                    'order_id' => $order->getId(),
                    'message' => 'User credits updated',
                    'total_delivery' => 1,
                    'videoKey' => $videoKey ?? null,
                ];
            },
            company: $order->company,
        );
    }
}
