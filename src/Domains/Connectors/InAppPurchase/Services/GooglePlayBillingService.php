<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Imdhemy\GooglePlay\ClientFactory as ProductClientFactory;
use Imdhemy\GooglePlay\Domain\Purchase\Entity\SubscriptionPurchase;
use Imdhemy\GooglePlay\Domain\Purchase\Subscription\SubscriptionPurchaseLineItem;
use Imdhemy\GooglePlay\Domain\Purchase\Subscription\SubscriptionState;
use Imdhemy\GooglePlay\Domain\Purchase\SubscriptionService;
use Imdhemy\GooglePlay\Infrastructure\Http\ClientFactory;
use Imdhemy\GooglePlay\Infrastructure\Transformer\Normalizer;
use Imdhemy\GooglePlay\Infrastructure\Transformer\Serializer;
use Imdhemy\GooglePlay\Products\ProductPurchase;
use Imdhemy\Purchases\Facades\Product;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum as InAppPurchaseConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\SubscriptionStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Laravel\Cashier\Subscription as CashierSubscription;
use Psr\Http\Client\ClientInterface;

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
     * purchases.subscriptionsv2 identifies a purchase by its token alone and does not check the product,
     * so callers read the state through lineItemOf(), which refuses another product's token.
     *
     * @throws ValidationException
     */
    public function getSubscription(string $purchaseToken): SubscriptionPurchase
    {
        $this->assertConfigured();

        return new SubscriptionService($this->subscriptionClient(), Normalizer::create(), Serializer::create())
            ->get($this->packageName(), $purchaseToken);
    }

    /**
     * @throws ValidationException
     */
    public function getProduct(string $productId, string $purchaseToken): ProductPurchase
    {
        $this->assertConfigured();

        return Product::googlePlay(ProductClientFactory::createWithJsonKey($this->serviceAccountConfig()))
            ->packageName($this->packageName())
            ->id($productId)
            ->token($purchaseToken)
            ->get();
    }

    /**
     * Play is the source of truth for a token, so the purchase mutation and the webhook both copy its state
     * as is, in either direction. A canceled subscription keeps access until its expiry; a revoked one comes
     * back expired with the expiry moved to the revocation. A missing expiry is refused, as it would never end.
     *
     * @throws ValidationException
     */
    public static function applyPurchaseState(
        CashierSubscription $subscription,
        SubscriptionPurchase $purchase,
        string $productId
    ): void {
        $expiry = self::expiryOf($purchase, $productId);
        $isFreeTrial = self::lineItemOf($purchase, $productId)->offerPhase?->freeTrial !== null;

        $subscription->stripe_status = self::statusOf($purchase)->value;
        $subscription->ends_at = $expiry;
        $subscription->trial_ends_at = $isFreeTrial ? $expiry : null;
    }

    /**
     * @throws ValidationException
     */
    public static function lineItemOf(SubscriptionPurchase $purchase, string $productId): SubscriptionPurchaseLineItem
    {
        foreach ($purchase->lineItems as $lineItem) {
            if ($lineItem->productId === $productId) {
                return $lineItem;
            }
        }

        throw new ValidationException('Google Play purchase token is not for subscription ' . $productId);
    }

    /**
     * @throws ValidationException
     */
    public static function expiryOf(SubscriptionPurchase $purchase, string $productId): Carbon
    {
        $expiresAt = self::lineItemOf($purchase, $productId)->expiresAt
            ?? throw new ValidationException('Google Play subscription has no expiry time');

        return Carbon::instance($expiresAt);
    }

    /**
     * @throws ValidationException
     */
    private static function statusOf(SubscriptionPurchase $purchase): SubscriptionStatusEnum
    {
        return match ($purchase->subscriptionState) {
            SubscriptionState::ACTIVE,
            // Play is still retrying the payment and has already extended the expiry, so access continues.
            SubscriptionState::IN_GRACE_PERIOD => SubscriptionStatusEnum::ACTIVE,
            SubscriptionState::CANCELED,
            SubscriptionState::PENDING_PURCHASE_CANCELED => SubscriptionStatusEnum::CANCELED,
            SubscriptionState::ON_HOLD => SubscriptionStatusEnum::PAST_DUE,
            SubscriptionState::PAUSED => SubscriptionStatusEnum::PAUSED,
            SubscriptionState::EXPIRED => SubscriptionStatusEnum::EXPIRED,
            SubscriptionState::PENDING => SubscriptionStatusEnum::PENDING,
            SubscriptionState::UNSPECIFIED => throw new ValidationException(
                'Google Play did not report a subscription state'
            ),
        };
    }

    protected function subscriptionClient(): ClientInterface
    {
        return ClientFactory::create($this->serviceAccountConfig());
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
