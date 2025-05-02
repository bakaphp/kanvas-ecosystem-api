<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Jobs;

use InvalidArgumentException;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ShopifyCompanyConfigWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $shopDomain = $this->webhookRequest->payload['shop'] ?? null;

        if (! $this->isValidShopifyDomain($shopDomain)) {
            throw new InvalidArgumentException("Invalid Shopify domain: {$shopDomain}");
        }

        $shopDomainConfig = $this->webhookRequest->app->get($shopDomain);

        return ! empty($shopDomainConfig) ? [
            'message' => 'Shopify domain configuration found',
            'config' => $shopDomain,
        ] : [
            'message' => 'No Shopify domain configuration found',
            'config' => null,
        ];
    }

    protected function isValidShopifyDomain(?string $domain): bool
    {
        if (empty($domain)) {
            return false;
        }

        $domain = preg_replace('/^https?:\/\//', '', $domain);

        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.myshopify\.com$/', $domain)) {
            return true;
        }

        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.[a-zA-Z]{2,}$/', $domain)) {
            return true;
        }

        return false;
    }
}
