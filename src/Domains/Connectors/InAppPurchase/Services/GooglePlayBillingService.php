<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Imdhemy\GooglePlay\ClientFactory;
use Imdhemy\GooglePlay\Products\ProductPurchase;
use Imdhemy\GooglePlay\Subscriptions\SubscriptionPurchase;
use Imdhemy\Purchases\Facades\Product;
use Imdhemy\Purchases\Facades\Subscription;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum as InAppPurchaseConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\SubscriptionStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Google Play Developer API access for an app, configured by its
 * google-payment-client-config (service account) and GOOGLE_PLAY_PACKAGE_NAME settings.
 */
class GooglePlayBillingService
{
    public function __construct(
        protected readonly AppInterface $app
    ) {
    }

    public function isConfigured(): bool
    {
        return ! empty($this->serviceAccountConfig()) && ! empty($this->packageName());
    }

    /**
     * isConfigured() also accepts google-client-config, which is often a Sign-In client with no Play
     * access; a webhook calling Play with it would fail on every notification.
     */
    public function hasPaymentServiceAccount(): bool
    {
        return ! empty($this->app->get(ConfigurationEnum::GOOGLE_PAYMENT_CLIENT_CONFIG->value))
            && ! empty($this->packageName());
    }

    /**
     * @throws ValidationException
     */
    public function getSubscription(string $subscriptionId, string $purchaseToken): SubscriptionPurchase
    {
        $this->assertConfigured();

        return Subscription::googlePlay(ClientFactory::createWithJsonKey($this->serviceAccountConfig()))
            ->packageName($this->packageName())
            ->id($subscriptionId)
            ->token($purchaseToken)
            ->get();
    }

    /**
     * @throws ValidationException
     */
    public function getProduct(string $productId, string $purchaseToken): ProductPurchase
    {
        $this->assertConfigured();

        return Product::googlePlay(ClientFactory::createWithJsonKey($this->serviceAccountConfig()))
            ->packageName($this->packageName())
            ->id($productId)
            ->token($purchaseToken)
            ->get();
    }

    /**
     * Play is the source of truth for a token, so the purchase mutation and the webhook both copy its state
     * as is, in either direction. A canceled subscription keeps access until its expiry; a revoked one comes
     * back with the expiry moved to the revocation. A missing expiry is refused, as it would never end.
     *
     * @throws ValidationException
     */
    public static function applyPurchaseState(CashierSubscription $subscription, SubscriptionPurchase $purchase): void
    {
        $expiry = self::expiryOf($purchase);

        $subscription->stripe_status = self::statusOf($purchase, $expiry)->value;
        $subscription->ends_at = $expiry;
        $subscription->trial_ends_at = $purchase->getPaymentState() === SubscriptionPurchase::PAYMENT_STATE_FREE_TRIAL
            ? $expiry
            : null;
    }

    /**
     * @throws ValidationException
     */
    public static function expiryOf(SubscriptionPurchase $purchase): Carbon
    {
        return $purchase->getExpiryTime()?->carbon
            ?? throw new ValidationException('Google Play subscription has no expiry time');
    }

    private static function statusOf(SubscriptionPurchase $purchase, Carbon $expiry): SubscriptionStatusEnum
    {
        return match (true) {
            $purchase->getCancellation() !== null => SubscriptionStatusEnum::CANCELED,
            $expiry->isPast() => SubscriptionStatusEnum::EXPIRED,
            $purchase->getPaymentState() === SubscriptionPurchase::PAYMENT_STATE_PENDING => SubscriptionStatusEnum::PENDING,
            default => SubscriptionStatusEnum::ACTIVE,
        };
    }

    /**
     * @throws ValidationException
     */
    protected function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new ValidationException('Google Play payment config or package name is missing');
        }
    }

    protected function serviceAccountConfig(): mixed
    {
        return $this->app->get(ConfigurationEnum::GOOGLE_PAYMENT_CLIENT_CONFIG->value)
            ?? $this->app->get(ConfigurationEnum::GOOGLE_CLIENT_CONFIG->value);
    }

    protected function packageName(): ?string
    {
        $packageName = $this->app->get(InAppPurchaseConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value);

        return is_string($packageName) && $packageName !== '' ? $packageName : null;
    }
}
