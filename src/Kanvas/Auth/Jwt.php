<?php

declare(strict_types=1);

namespace Kanvas\Auth;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha512;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\NoConstraintsGiven;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;

class Jwt
{
    /**
     * Get the JWT Configuration.
     *
     * @return Configuration
     */
    public static function getConfig() : Configuration
    {
        return  Configuration::forSymmetricSigner(
            // You may use any HMAC variations (256, 384, and 512)
            new Sha512(),
            InMemory::plainText(config('kanvas.jwt.secretKey'))
        );
    }

    /**
     * Create a new session based off the refresh token session id.
     *
     * @param string $sessionId
     * @param string $email
     *
     * @return Plain
     */
    public static function createToken(string $sessionId, string $email, float $expirationAt = 0) : Plain
    {
        $now = new DateTimeImmutable();
        $config = self::getConfig();
        //get the expiration in hours
        $expiration = $expirationAt == 0 ? ceil((config('kanvas.jwt.payload.exp') ?? 604800) / 3600) : $expirationAt;

        //https://lcobucci-jwt.readthedocs.io/en/latest/issuing-tokens/
        return $config->builder()
                ->issuedBy(getenv('TOKEN_AUDIENCE'))
                ->permittedFor(getenv('TOKEN_AUDIENCE'))
                ->identifiedBy($sessionId)
                ->issuedAt($now)
                ->canOnlyBeUsedAfter($now)
                ->expiresAt($now->modify('+' . $expiration . ' hour'))
                ->withClaim('sessionId', $sessionId)
                ->withClaim('email', $email)
                // Builds a new token
                ->getToken($config->signer(), $config->signingKey());
    }

    /**
     * Given a JWT token validate it.
     *
     * @param Token $token
     *
     * @throws RequiredConstraintsViolated
     * @throws NoConstraintsGiven
     *
     * @return bool
     */
    public static function validateToken(Token $token) : bool
    {
        $config = Jwt::getConfig();

        return $config->validator()->validate(
            $token,
            new IssuedBy(getenv('TOKEN_AUDIENCE')),
            new SignedWith($config->signer(), $config->verificationKey())
        );
    }
}
