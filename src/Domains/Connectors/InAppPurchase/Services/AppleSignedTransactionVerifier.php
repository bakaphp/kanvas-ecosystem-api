<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Services;

use Baka\Contracts\AppInterface;
use Imdhemy\AppStore\Jws\JsonWebSignature;
use Imdhemy\AppStore\Jws\Parser;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Throwable;

/**
 * App Store and sandbox transactions must pass AppleJwsVerifier (Apple's pinned chain plus the App
 * Store purpose OIDs). Transactions signed by Xcode's local StoreKit testing certificate are accepted
 * only when the app opts in with IAP_ALLOW_XCODE_STOREKIT_TESTING, so a local or factory test never
 * unlocks a production app.
 */
class AppleSignedTransactionVerifier
{
    public const string ENVIRONMENT_XCODE = 'Xcode';

    public function __construct(
        private readonly AppInterface $app,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function verify(string $signedTransaction): array
    {
        $jws = $this->parse($signedTransaction);

        $isSigned = $this->isXcodeTransaction($jws)
            ? $this->isSignedByXcodeCertificate($jws)
            : app(AppleJwsVerifier::class)->verify($jws);

        if (! $isSigned) {
            throw new ValidationException('Apple signed transaction signature could not be verified');
        }

        $payload = $jws->getClaims();
        $this->assertBundle($payload);

        return $payload;
    }

    private function parse(string $signedTransaction): JsonWebSignature
    {
        try {
            return Parser::toJws($signedTransaction);
        } catch (Throwable) {
            throw new ValidationException('Apple signed transaction is not a JWS');
        }
    }

    /**
     * Read before the signature is checked, only to choose the trust anchor. A forged 'Xcode' claim
     * still needs the app's opt-in, which production apps never set.
     */
    private function isXcodeTransaction(JsonWebSignature $jws): bool
    {
        return ($jws->getClaims()['environment'] ?? null) === self::ENVIRONMENT_XCODE;
    }

    /**
     * Xcode signs with a self-signed certificate, so the leaf only proves the payload was not edited
     * after signing; the opt-in setting is the actual trust decision.
     */
    private function isSignedByXcodeCertificate(JsonWebSignature $jws): bool
    {
        if (! $this->app->getBool(ConfigurationEnum::IAP_ALLOW_XCODE_STOREKIT_TESTING->value)) {
            throw new ValidationException('Xcode StoreKit testing transactions are not accepted for this app');
        }

        $leaf = $jws->getHeaders()['x5c'][0] ?? null;

        if (! is_string($leaf) || $leaf === '') {
            return false;
        }

        try {
            return new Validator()->validate(
                $jws,
                new SignedWith(new Sha256(), InMemory::plainText(AppleJwsVerifier::toPem($leaf)))
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Fails closed: an app without APPLE_BUNDLE_ID accepts nothing, since any app's validly signed
     * transaction or notification would otherwise unlock it. Also used by the server-notification webhook.
     *
     * @param array<string, mixed> $payload a transaction, or a notification's data
     * @throws ValidationException
     */
    public function assertBundle(array $payload): void
    {
        $expected = $this->configuredBundleId();

        if ($expected === '') {
            throw new ValidationException(
                'APPLE_BUNDLE_ID is not configured for this app, so Apple payloads cannot be accepted'
            );
        }

        if (($payload['bundleId'] ?? null) !== $expected) {
            throw new ValidationException('Apple payload belongs to another app');
        }
    }

    /**
     * Legacy receipts (StoreKit 1) are what every live app sends, so an app without APPLE_BUNDLE_ID is
     * reported rather than refused: failing closed here would stop every Apple purchase on deploy. Once
     * Sentry stops seeing this, the receipt flows can call assertBundle instead.
     *
     * @throws ValidationException
     */
    public function assertReceiptBundle(?string $bundleId): void
    {
        if ($this->configuredBundleId() === '') {
            report(new ValidationException(
                'APPLE_BUNDLE_ID is not configured for app ' . $this->app->getId()
                . ', so an Apple receipt was accepted without a bundle check'
            ));

            return;
        }

        $this->assertBundle(['bundleId' => $bundleId]);
    }

    private function configuredBundleId(): string
    {
        return (string) ($this->app->get(ConfigurationEnum::APPLE_BUNDLE_ID->value) ?? '');
    }
}
