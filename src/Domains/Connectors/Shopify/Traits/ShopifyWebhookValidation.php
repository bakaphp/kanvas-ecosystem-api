<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Traits;

use Illuminate\Validation\UnauthorizedException;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;

trait ShopifyWebhookValidation
{
    /**
    * Validate Shopify webhook signature
    */
    protected function validateShopifyWebhook(): void
    {
        // Get the webhook secret from your configuration or receiver settings
        $webhookSecret = $this->getWebhookSecret();

        if (empty($webhookSecret)) {
            throw new UnauthorizedException('Shopify webhook secret not configured');
        }

        // Get the HMAC header from the webhook request
        $hmacHeader = $this->getHmacHeader();

        if (empty($hmacHeader)) {
            throw new UnauthorizedException('Missing Shopify HMAC signature');
        }

        // Get the raw payload body
        $rawPayload = $this->getRawPayload();

        // Calculate the expected HMAC
        $expectedHmac = base64_encode(hash_hmac('sha256', $rawPayload, $webhookSecret, true));

        // Compare the signatures
        if (! hash_equals($expectedHmac, $hmacHeader)) {
            throw new UnauthorizedException('Invalid Shopify webhook signature');
        }
    }

    /**
     * Get the webhook secret from configuration
     */
    protected function getWebhookSecret(): string
    {
        // Option 1: From receiver configuration (if stored there)
        if (isset($this->receiver->configuration[CustomFieldEnum::SHOPIFY_WEBHOOK_SECRET->value])) {
            return $this->receiver->configuration[CustomFieldEnum::SHOPIFY_WEBHOOK_SECRET->value];
        }

        // Option 2: From app configuration
        if (isset($this->receiver->app->configuration[CustomFieldEnum::SHOPIFY_WEBHOOK_SECRET->value])) {
            return $this->receiver->app->configuration[CustomFieldEnum::SHOPIFY_WEBHOOK_SECRET->value];
        }

        if ($this->receiver->company->get(CustomFieldEnum::SHOPIFY_API_KEY_V2->value) !== null) {
            return $this->receiver->company->get(CustomFieldEnum::SHOPIFY_API_KEY_V2->value);
        }

        throw new UnauthorizedException('Shopify webhook secret not found in configuration');
    }

    /**
     * Get HMAC header from the webhook request
     */
    protected function getHmacHeader(): ?string
    {
        $headers = $this->webhookRequest->headers ?? [];

        if (isset($headers['X-Shopify-Hmac-Sha256'][0])) {
            return $headers['X-Shopify-Hmac-Sha256'][0];
        }

        if (isset($headers['x-shopify-hmac-sha256'][0])) {
            return $headers['x-shopify-hmac-sha256'][0];
        }

        return null;
    }

    /**
     * Get raw payload for HMAC calculation
     */
    protected function getRawPayload(): string
    {
        // If payload is already JSON, convert back to string
        if (is_array($this->webhookRequest->payload)) {
            return json_encode($this->webhookRequest->payload);
        }

        return (string) $this->webhookRequest->payload;
    }
}
