<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Contracts;

use Kanvas\Connectors\Facebook\OAuth\FacebookOAuthProvider;
use Kanvas\Connectors\Shopify\OAuth\ShopifyOAuthProvider;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\ReceiverWebhook;

class OAuthProviderFactory
{
    public static function make(ReceiverWebhook $receiver): OAuthProviderInterface
    {
        /** @var array<string, mixed> $config */
        $config = $receiver->configuration;
        $provider = (string) ($config['oauth_provider'] ?? 'shopify');

        return match ($provider) {
            'shopify' => new ShopifyOAuthProvider(),
            'facebook' => new FacebookOAuthProvider(),
            default => throw new ValidationException("Unsupported OAuth provider: {$provider}"),
        };
    }
}
