<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Services;

use Imdhemy\AppStore\Jws\AppStoreJwsVerifier;
use Imdhemy\AppStore\Jws\JsonWebSignature;
use Imdhemy\AppStore\Jws\JwsVerifier;
use Lcobucci\JWT\Validation\ConstraintViolation;
use Throwable;

/**
 * The library pins the Apple roots and checks the chain and signature, but not the purpose OIDs or
 * validity dates that Apple's App Store Server Library also requires, so any certificate Apple's WWDR
 * G6 issued (not only App Store receipt signing) would otherwise pass.
 */
class AppleJwsVerifier implements JwsVerifier
{
    public const string LEAF_OID = '1.2.840.113635.100.6.11.1';
    public const string INTERMEDIATE_OID = '1.2.840.113635.100.6.2.1';

    public function verify(JsonWebSignature $jws): bool
    {
        try {
            /** @var JwsVerifier $chainVerifier */
            $chainVerifier = app(AppStoreJwsVerifier::class);

            if (! $chainVerifier->verify($jws)) {
                return false;
            }

            $certificates = $this->parseChain($jws->getHeaders()['x5c'] ?? []);

            return $certificates !== null
                && $this->hasExtension($certificates[0], self::LEAF_OID)
                && $this->hasExtension($certificates[1], self::INTERMEDIATE_OID)
                && $this->areValidAt($certificates, $this->signedAt($jws));
        } catch (ConstraintViolation) {
            return false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * x5c holds base64 DER; OpenSSL and lcobucci both want PEM.
     */
    public static function toPem(string $base64Der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split($base64Der, 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function parseChain(mixed $x5c): ?array
    {
        if (! is_array($x5c) || count($x5c) < 2) {
            return null;
        }

        $certificates = [];

        foreach ($x5c as $encoded) {
            if (! is_string($encoded)) {
                return null;
            }

            $parsed = openssl_x509_parse(self::toPem($encoded));

            if ($parsed === false) {
                return null;
            }

            $certificates[] = $parsed;
        }

        return $certificates;
    }

    /**
     * @param array<string, mixed> $certificate
     */
    private function hasExtension(array $certificate, string $oid): bool
    {
        $extensions = $certificate['extensions'] ?? [];

        return is_array($extensions) && array_key_exists($oid, $extensions);
    }

    /**
     * Apple checks validity at signing time, so a receipt signed before a leaf expired stays valid.
     */
    private function signedAt(JsonWebSignature $jws): int
    {
        $signedDate = $jws->getClaims()['signedDate'] ?? null;

        return is_numeric($signedDate) ? intdiv((int) $signedDate, 1000) : time();
    }

    /**
     * @param array<int, array<string, mixed>> $certificates
     */
    private function areValidAt(array $certificates, int $timestamp): bool
    {
        foreach ($certificates as $certificate) {
            $validFrom = $certificate['validFrom_time_t'] ?? null;
            $validTo = $certificate['validTo_time_t'] ?? null;

            if (! is_int($validFrom) || ! is_int($validTo)) {
                return false;
            }

            if ($timestamp < $validFrom || $timestamp > $validTo) {
                return false;
            }
        }

        return true;
    }
}
