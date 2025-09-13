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

                foreach ($order->items as $item) {
                    $variant = $item->variant;
                    if (strtolower($variant->product->type->name) !== 'pickamodel') {
                        continue;
                    }

                    $aiModelKey = $variant->getAttributeBySlug('ai-model')?->value;
                    $aiModelRelated = $variant->getAttributeBySlug('ai-model-related')?->value;
                    $purchaseType = match (strtolower($variant->product->categories->first()->name)) {
                        'texttotext' => 'text',
                        'imagetovideo' => 'video',
                        'texttoimage' => 'image',
                        'texttovideo' => 'video',
                        'imagetoimage' => 'image',
                        default => 'text',
                    };

                    $orderCredit[$purchaseType] ??= [];

                    if (empty($aiModelKey)) {
                        continue;
                    }

                    if (isset($orderCredit[$purchaseType][$aiModelKey])) {
                        $orderCredit[$purchaseType][$aiModelKey]++;
                        if ($aiModelRelated !== null && $aiModelRelated !== $aiModelKey) {
                            $orderCredit[$purchaseType][$aiModelRelated]++;
                        }
                    } else {
                        $orderCredit[$purchaseType][$aiModelKey] = 1;
                        if ($aiModelRelated != null && $aiModelRelated !== $aiModelKey) {
                            $orderCredit[$purchaseType][$aiModelRelated] = 1;
                        }
                    }
                }

                $order->user->set(
                    'order_credits',
                    $orderCredit,
                    true
                );

                return [
                    'order_id' => $order->getId(),
                    'message' => 'User credits updated',
                    'total_delivery' => 1,
                    'key' => $aiModelKey ?? null,
                ];
            },
            company: $order->company,
        );
    }
}
