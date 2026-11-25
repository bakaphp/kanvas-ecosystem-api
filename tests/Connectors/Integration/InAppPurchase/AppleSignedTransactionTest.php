<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Carbon\Carbon;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Imdhemy\AppStore\Jws\JsonWebSignature;
use Imdhemy\AppStore\Jws\JwsVerifier;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromAppleReceiptAction;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\AppleInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Services\AppleJwsVerifier;
use Kanvas\Connectors\InAppPurchase\Services\AppleSignedTransactionVerifier;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription as CashierSubscription;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the verifier reads APPLE_BUNDLE_ID and IAP_ALLOW_XCODE_STOREKIT_TESTING, which are app
 * settings shared by every parallel process.
 */
#[Group('serial')]
class AppleSignedTransactionTest extends TestCase
{
    use DatabaseTransactions;

    // A purchase files the suite's shared user as a CRM person; committed, it turns that user's email
    // into a company contact for every serial test after this one. Not inventory: CreateProductAction
    // retries its own transaction, which a savepoint would break.
    protected $connectionsToTransact = [null, 'crm', 'commerce'];

    private const string BUNDLE_ID = 'com.kanvas.signed.test';

    private Apps $kanvasApp;
    private string $sku;
    private string $originalTransactionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(ConfigurationEnum::APPLE_BUNDLE_ID->value, self::BUNDLE_ID);
        $this->kanvasApp->del(ConfigurationEnum::IAP_ALLOW_XCODE_STOREKIT_TESTING->value);

        $this->sku = 'signed_sub_' . uniqid();
        $this->originalTransactionId = 'signed_orig_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(ConfigurationEnum::APPLE_BUNDLE_ID->value);
        $this->kanvasApp->del(ConfigurationEnum::IAP_ALLOW_XCODE_STOREKIT_TESTING->value);

        parent::tearDown();
    }

    public function testValidTransactionCreatesOrderAndActiveSubscription(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();

        $order = $this->executeSigned($this->unsignedJws($this->transactionClaims('tx_' . uniqid())));

        $this->assertEquals(
            $this->originalTransactionId,
            $order->metadata['apple_subscription']['original_transaction_id']
        );
        $this->assertTrue($order->hasTag(['iap-subscription']));

        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertEquals('apple:' . $this->sku, $subscription->type);
        $this->assertTrue($subscription->ends_at->isFuture());
        $this->assertNull($subscription->trial_ends_at);
    }

    public function testOnlyFreeTrialOfferSetsTrialEndsAt(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();

        $introOffer = $this->transactionClaims('tx_' . uniqid())
            + ['offerType' => 1, 'offerDiscountType' => 'PAY_AS_YOU_GO'];
        $order = $this->executeSigned($this->unsignedJws($introOffer));

        $this->assertTrue($order->metadata['apple_subscription']['is_in_intro_offer_period']);
        $this->assertFalse($order->metadata['apple_subscription']['is_trial_period']);
        $this->assertNull($this->findSubscription()->trial_ends_at);

        $trial = $this->transactionClaims('tx_' . uniqid(), now()->addMonths(2))
            + ['offerType' => 1, 'offerDiscountType' => 'FREE_TRIAL'];
        $this->executeSigned($this->unsignedJws($trial));

        $this->assertNotNull($this->findSubscription()->trial_ends_at);
    }

    public function testSameUserResubmittingIsIdempotent(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();
        $jws = $this->unsignedJws($this->transactionClaims('tx_' . uniqid()));

        $first = $this->executeSigned($jws);
        $second = $this->executeSigned($jws);

        $this->assertEquals($first->getId(), $second->getId());
        $this->assertEquals(1, $this->countLineageOrders());
        $this->assertEquals('active', $this->findSubscription()->stripe_status);
    }

    public function testStaleTransactionDoesNotDowngradeSubscription(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();

        $current = $this->executeSigned(
            $this->unsignedJws($this->transactionClaims('tx_new_' . uniqid(), now()->addMonths(2)))
        );
        $currentEndsAt = $this->findSubscription()->ends_at;

        $stale = $this->executeSigned(
            $this->unsignedJws($this->transactionClaims('tx_old_' . uniqid(), now()->subDay()))
        );

        $this->assertEquals($current->getId(), $stale->getId());
        $this->assertEquals(1, $this->countLineageOrders());

        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertTrue($subscription->ends_at->equalTo($currentEndsAt));
    }

    public function testTransactionOfAnotherUserIsNotTakenOver(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();

        $otherUser = $this->createUser();
        $otherCustomer = AppsStripeCustomer::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => 0,
            'users_id' => $otherUser->getId(),
            'provider' => 'apple',
            'stripe_id' => $this->originalTransactionId,
        ]);
        CashierSubscription::create([
            'apps_stripe_customer_id' => $otherCustomer->getId(),
            'type' => 'apple:' . $this->sku,
            'stripe_id' => $this->originalTransactionId,
            'stripe_price' => $this->sku,
            'stripe_status' => 'active',
            'quantity' => 1,
            'ends_at' => now()->addMonth(),
        ]);

        try {
            $this->executeSigned(
                $this->unsignedJws($this->transactionClaims('tx_' . uniqid(), now()->addMonths(2)))
            );
            $this->fail('Another account\'s purchase was accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('belongs to another account', $e->getMessage());
        }

        $subscription = CashierSubscription::where('stripe_id', $this->originalTransactionId)->firstOrFail();
        $this->assertEquals($otherCustomer->getId(), $subscription->apps_stripe_customer_id);
        $this->assertEquals(0, $this->countLineageOrders());
    }

    public function testRejectsTransactionForAnotherProduct(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();
        $claims = ['productId' => 'some.other.product'] + $this->transactionClaims('tx_' . uniqid());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('different product');

        $this->executeSigned($this->unsignedJws($claims));
    }

    public function testRejectsRevokedTransaction(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();
        $claims = $this->transactionClaims('tx_' . uniqid()) + ['revocationDate' => now()->getTimestampMs()];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('revoked');

        $this->executeSigned($this->unsignedJws($claims));
    }

    public function testRejectsConsumableTransaction(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();
        $claims = ['type' => 'Consumable'] + $this->transactionClaims('tx_' . uniqid());
        unset($claims['expiresDate']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not an auto-renewable subscription');

        $this->executeSigned($this->unsignedJws($claims));
    }

    public function testRejectsNonRenewingSubscriptionTransaction(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();
        $claims = ['type' => 'Non-Renewing Subscription'] + $this->transactionClaims('tx_' . uniqid());

        try {
            $this->executeSigned($this->unsignedJws($claims));
            $this->fail('A non-renewing purchase was stored as a subscription');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not an auto-renewable subscription', $e->getMessage());
        }

        $subscriptions = CashierSubscription::where('stripe_id', $this->originalTransactionId)->count();
        $this->assertEquals(0, $subscriptions);
    }

    public function testRejectsSubscriptionTransactionWithoutExpiresDate(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();
        $claims = $this->transactionClaims('tx_' . uniqid());
        unset($claims['expiresDate']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no expiration date');

        $this->executeSigned($this->unsignedJws($claims));
    }

    public function testResubmittingRebuildsAMissingSubscription(): void
    {
        $this->trustUnsignedPayloads();
        $this->createProduct();
        $jws = $this->unsignedJws($this->transactionClaims('tx_' . uniqid()));

        $first = $this->executeSigned($jws);
        $this->findSubscription()->delete();

        $second = $this->executeSigned($jws);

        $this->assertEquals($first->getId(), $second->getId());
        $this->assertEquals(1, $this->countLineageOrders());
        $this->assertEquals('active', $this->findSubscription()->stripe_status);
    }

    public function testRejectsTransactionOfAnotherBundle(): void
    {
        $this->trustUnsignedPayloads();
        $claims = ['bundleId' => 'com.someone.else'] + $this->transactionClaims('tx_' . uniqid());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belongs to another app');

        new AppleSignedTransactionVerifier($this->kanvasApp)->verify($this->unsignedJws($claims));
    }

    public function testRejectsEveryTransactionWhenBundleIdIsNotConfigured(): void
    {
        $this->trustUnsignedPayloads();
        $this->kanvasApp->del(ConfigurationEnum::APPLE_BUNDLE_ID->value);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(ConfigurationEnum::APPLE_BUNDLE_ID->value);

        new AppleSignedTransactionVerifier($this->kanvasApp)
            ->verify($this->unsignedJws($this->transactionClaims('tx_' . uniqid())));
    }

    public function testRejectsXcodeTransactionWhenTestingIsNotAllowed(): void
    {
        $jws = $this->selfSignedJws($this->xcodeClaims());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Xcode StoreKit testing');

        new AppleSignedTransactionVerifier($this->kanvasApp)->verify($jws);
    }

    public function testAcceptsXcodeTransactionWhenTestingIsAllowed(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::IAP_ALLOW_XCODE_STOREKIT_TESTING->value, 1);
        $claims = $this->xcodeClaims();

        $payload = new AppleSignedTransactionVerifier($this->kanvasApp)
            ->verify($this->selfSignedJws($claims));

        $this->assertEquals($claims['transactionId'], $payload['transactionId']);
        $this->assertEquals(AppleSignedTransactionVerifier::ENVIRONMENT_XCODE, $payload['environment']);
    }

    public function testRejectsEditedXcodeTransaction(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::IAP_ALLOW_XCODE_STOREKIT_TESTING->value, 1);
        [$header, , $signature] = explode('.', $this->selfSignedJws($this->xcodeClaims()));
        $extendedClaims = ['expiresDate' => now()->addYears(5)->getTimestampMs()] + $this->xcodeClaims();
        $edited = JWT::urlsafeB64Encode((string) json_encode($extendedClaims));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('could not be verified');

        new AppleSignedTransactionVerifier($this->kanvasApp)->verify("{$header}.{$edited}.{$signature}");
    }

    public function testRealVerifierRejectsChainNotIssuedByApple(): void
    {
        [$rootKey, $root] = $this->certificate('Fake Apple Root CA - G3');
        [$intermediateKey, $intermediate] = $this->certificate('Fake Apple WWDR CA - G6', $root, $rootKey);
        [$leafKey, $leaf] = $this->certificate(
            'Fake Prod ECC Mac App Store',
            $intermediate,
            $intermediateKey
        );

        $jws = $this->signJws(
            $this->transactionClaims('tx_' . uniqid()),
            $leafKey,
            [$leaf, $intermediate, $root]
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('could not be verified');

        new AppleSignedTransactionVerifier($this->kanvasApp)->verify($jws);
    }

    private function trustUnsignedPayloads(): void
    {
        $this->app->instance(AppleJwsVerifier::class, new class () implements JwsVerifier {
            public function verify(JsonWebSignature $jws): bool
            {
                return true;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionClaims(string $transactionId, ?Carbon $expiresAt = null): array
    {
        return [
            'transactionId' => $transactionId,
            'originalTransactionId' => $this->originalTransactionId,
            'bundleId' => self::BUNDLE_ID,
            'productId' => $this->sku,
            'purchaseDate' => now()->subMinute()->getTimestampMs(),
            'expiresDate' => ($expiresAt ?? now()->addMonth())->getTimestampMs(),
            'quantity' => 1,
            'type' => 'Auto-Renewable Subscription',
            'environment' => 'Sandbox',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function xcodeClaims(): array
    {
        return ['environment' => AppleSignedTransactionVerifier::ENVIRONMENT_XCODE]
            + $this->transactionClaims('0');
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function unsignedJws(array $claims): string
    {
        $header = JWT::urlsafeB64Encode((string) json_encode(['alg' => 'ES256', 'x5c' => ['unused']]));
        $payload = JWT::urlsafeB64Encode((string) json_encode($claims));

        return "{$header}.{$payload}." . JWT::urlsafeB64Encode('unsigned');
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function selfSignedJws(array $claims): string
    {
        [$key, $certificate] = $this->certificate('StoreKit Testing in Xcode');

        return $this->signJws($claims, $key, [$certificate]);
    }

    /**
     * @param array<string, mixed> $claims
     * @param list<OpenSSLCertificate> $chain leaf first
     */
    private function signJws(array $claims, OpenSSLAsymmetricKey $key, array $chain): string
    {
        openssl_pkey_export($key, $privateKeyPem);

        $x5c = array_map(static function (OpenSSLCertificate $certificate): string {
            openssl_x509_export($certificate, $pem);

            return (string) preg_replace('/-----[A-Z ]+-----|\s/', '', $pem);
        }, $chain);

        return JWT::encode($claims, $privateKeyPem, 'ES256', head: ['x5c' => $x5c]);
    }

    /**
     * @return array{0: OpenSSLAsymmetricKey, 1: OpenSSLCertificate}
     */
    private function certificate(
        string $commonName,
        ?OpenSSLCertificate $issuer = null,
        ?OpenSSLAsymmetricKey $issuerKey = null
    ): array {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign(
            $csr,
            $issuer,
            $issuerKey ?? $key,
            1,
            ['digest_alg' => 'sha256']
        );

        return [$key, $certificate];
    }

    private function executeSigned(string $signedTransaction): Order
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateOrderFromAppleReceiptAction(
            new AppleInAppPurchaseReceipt(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                region: Regions::fromApp($this->kanvasApp)->fromCompany($company)->firstOrFail(),
                product_id: $this->sku,
                transaction_id: 'client_reported_tx',
                receipt: '',
                transaction_date: now()->getTimestampMs(),
                is_renewable_subscription: true,
                signed_transaction: $signedTransaction
            )
        )->execute();
    }

    private function createProduct(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($this->kanvasApp, $user, $company)->run();

        new CreateProductAction(
            new Product(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: $this->sku,
                sku: $this->sku,
                warehouses: [[
                    'quantity' => 10,
                    'price' => 9.99,
                ]]
            ),
            $user
        )->execute();
    }

    private function findSubscription(): CashierSubscription
    {
        return CashierSubscription::where('stripe_id', $this->originalTransactionId)->firstOrFail();
    }

    private function countLineageOrders(): int
    {
        return Order::fromApp($this->kanvasApp)
            ->where('users_id', auth()->user()->getId())
            ->whereJsonContains(
                'metadata->apple_subscription->original_transaction_id',
                $this->originalTransactionId
            )
            ->count();
    }
}
