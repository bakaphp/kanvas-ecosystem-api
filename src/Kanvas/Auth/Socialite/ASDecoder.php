<?php

declare(strict_types=1);

namespace Kanvas\Auth\Socialite;

use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Decode Sign In with Apple identity token, and produce an ASPayload for
 * utilizing in backend auth flows to verify validity of provided user creds.
 * @link https://github.com/GriffinLedingham/php-apple-signin
 */
class ASDecoder
{
    /**
     * Parse a provided Sign In with Apple identity token.
     */
    public static function getAppleSignInPayload(string $identityToken): ?object
    {
        return self::decodeIdentityToken($identityToken);
    }

    /**
     * Decode the Apple encoded JWT using Apple's public key for the signing.
     */
    public static function decodeIdentityToken(string $identityToken): object
    {
        $publicKeyData = self::fetchPublicKey();

        $publicKey = $publicKeyData['publicKey'];
        $alg = $publicKeyData['alg'];

        //$payload = JWT::decode($identityToken, $publicKey, [$alg]);
        $payload = JWT::decode($identityToken, $publicKey);

        return $payload;
    }

    /**
     * Fetch Apple's public key from the auth/keys REST API to use to decode
     * the Sign In JWT.
     */
    public static function fetchPublicKey(): array
    {
        $publicKeys = file_get_contents('https://appleid.apple.com/auth/keys');
        $decodedPublicKeys = json_decode($publicKeys, true);

        if (! isset($decodedPublicKeys['keys']) || count($decodedPublicKeys['keys']) < 1) {
            throw new Exception('Invalid key format.');
        }

        $parsedKeyData = $decodedPublicKeys['keys'][0];
        $parsedPublicKey = JWK::parseKey($parsedKeyData);
       /*  $publicKeyDetails = openssl_pkey_get_details($parsedPublicKey);

        if (! isset($publicKeyDetails['key'])) {
            throw new Exception('Invalid public key details.');
        } */

        return [
            'publicKey' => $parsedPublicKey->getKeyMaterial(),
            'alg' => $parsedKeyData['alg'],
        ];
    }
}
