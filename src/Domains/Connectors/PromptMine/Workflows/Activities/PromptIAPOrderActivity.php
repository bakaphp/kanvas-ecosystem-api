<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;
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
                    $creditQuantity = $variant->getAttributeBySlug('bundle-quantity')?->value ?? 1;
                    $bundleModels = $variant->getAttributeBySlug('ai-bundle-model')?->value;

                    $purchaseType = match (strtolower($variant->product->categories->first()->slug)) {
                        'texttotext' => 'text',
                        'imagetovideo' => 'video',
                        'texttoimage' => 'image',
                        'bundleimagetoimage' => 'image',
                        'bundlemultiimagetoimage' => 'image',
                        'bundletexttoimage' => 'image',
                        'texttovideo' => 'video',
                        'imagetoimage' => 'image',
                        'multiimagetoimage' => 'image',
                        'bundletexttovideo' => 'video',
                        'bundlemultiimagetovideo' => 'video',
                        'bundleimagetovideo' => 'video',
                        'framestovideo' => 'video',
                        'referencestovideo' => 'video',
                        'bundleframestovideo' => 'video',
                        'bundlereferencestovideo' => 'video',
                        default => 'text',
                    };

                    $orderCredit[$purchaseType] ??= [];

                    if (empty($aiModelKey)) {
                        continue;
                    }

                    // Add credits for base AI model
                    $orderCredit[$purchaseType][$aiModelKey] =
                        ($orderCredit[$purchaseType][$aiModelKey] ?? 0) + $creditQuantity;

                    // Add credits for related model
                    if ($aiModelRelated && $aiModelRelated !== $aiModelKey) {
                        $orderCredit[$purchaseType][$aiModelRelated] =
                            ($orderCredit[$purchaseType][$aiModelRelated] ?? 0) + $creditQuantity;
                    }

                    // Bundle models (multi-model package)
                    if (is_array($bundleModels)) {
                        foreach ($bundleModels as $type => $models) {
                            $orderCredit[$type] ??= [];

                            foreach ($models as $modelKey => $credits) {
                                $orderCredit[$type][$modelKey] =
                                    ($orderCredit[$type][$modelKey] ?? 0) + $credits;
                            }
                        }
                    }
                }

                //if (! $order->hasTag([WalletConfigurationEnum::WALLET_CREDIT_TAG->value])) {
                $order->user->set(
                    'order_credits',
                    $orderCredit,
                    true
                );
                //}

                return [
                    'order_id' => $order->getId(),
                    'message' => 'User credits updated',
                    'total_delivery' => $totalCredits ?? 1,
                    'key' => $aiModelKey ?? null,
                    'related_key' => $aiModelRelated ?? null,
                    'purchase_type' => $purchaseType ?? null,
                ];
            },
            company: $order->company,
        );
    }
}
