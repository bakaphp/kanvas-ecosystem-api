<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Notifications;

use Kanvas\Notifications\Notification;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Enums\EmailTemplateEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;

class NewOrderNotification extends Notification
{
    public function __construct(
        Order $order,
        array $data,
    ) {
        parent::__construct($order, $data);
        //$this->setType(EnumsEmailTemplateEnum::BLANK->value);

        // Check if this is an eSIM order and use appropriate template
        $templateName = $this->isEsimOrder($order) 
            ? EmailTemplateEnum::NEW_ORDER_ESIM->value 
            : EmailTemplateEnum::NEW_ORDER->value;

        $this->setTemplateName($templateName);
        $this->setData($data);

        if (! $this->app->get(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value)) {
            $this->channels = [];
        }
    }

    /**
     * Check if the order contains eSIM products.
     */
    private function isEsimOrder(Order $order): bool
    {
        foreach ($order->items as $item) {
            $variant = $item->variant;

            // Check if variant has eSIM-related attributes
            if ($variant->getAttributeBySlug('esim_bundle_type') || 
                $variant->getAttributeBySlug('esim_days') || 
                $variant->getAttributeBySlug('esim-days')) {
                return true;
            }

            // Check if product has eSIM provider
            $provider = $variant->getAttributeBySlug('variant_provider') ?? 
                       $variant->product->getAttributeBySlug('provider');

            if ($provider && in_array(strtolower($provider->value), ['esimgo', 'airalo', 'cmlink', 'venta_mobile', 'ventamobile', 'easy_activation'])) {
                return true;
            }

            // Check if order has eSIM metadata
            if ($order->metadata && (
                isset($order->metadata['data']['iccid']) || 
                isset($order->metadata['order_esim_metadata']) ||
                $order->get('order_esim_metadata')
            )) {
                return true;
            }
        }

        return false;
    }
}
