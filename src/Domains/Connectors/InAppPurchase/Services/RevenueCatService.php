<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\RevenueCatSubscriptionState;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * RevenueCat REST API v1 for an app, configured by its REVENUECAT_SECRET_KEY setting (a secret API key).
 */
class RevenueCatService
{
    private const string SUBSCRIBERS_URL = 'https://api.revenuecat.com/v1/subscribers/';
    private const int TIMEOUT_SECONDS = 15;

    public function __construct(
        protected readonly AppInterface $app
    ) {
    }

    public function hasSecretKey(): bool
    {
        return $this->secretKey() !== '';
    }

    /**
     * @return array<string, mixed> the response's subscriber object
     * @throws RequestException|ValidationException
     */
    public function getSubscriber(string $appUserId): array
    {
        if (! $this->hasSecretKey()) {
            throw new ValidationException('No RevenueCat secret key configured');
        }

        $subscriber = Http::withToken($this->secretKey())
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->get(self::SUBSCRIBERS_URL . rawurlencode($appUserId))
            ->throw()
            ->json('subscriber');

        return is_array($subscriber) ? $subscriber : [];
    }

    /**
     * Null when the subscriber has no subscription to the product, or RevenueCat reports no expiry for it.
     *
     * @throws RequestException|ValidationException
     */
    public function getSubscriptionState(string $appUserId, string $productId): ?RevenueCatSubscriptionState
    {
        $entry = $this->getSubscriber($appUserId)['subscriptions'][$productId] ?? null;

        return is_array($entry) ? RevenueCatSubscriptionState::fromSubscriberEntry($productId, $entry) : null;
    }

    protected function secretKey(): string
    {
        return (string) $this->app->get(ConfigurationEnum::REVENUECAT_SECRET_KEY->value);
    }
}
