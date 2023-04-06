<?php

declare(strict_types=1);

namespace Kanvas\Traits;

use Kanvas\Auth\Jwt;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

use function time;

trait TokenTrait
{
    /**
     * Returns the JWT token object.
     */
    protected function getToken(string $token): Token
    {
        $config = Jwt::getConfig();

        return $config->parser()->parse($token);
    }

    /**
     * Returns the default audience for the tokens.
     */
    protected function getTokenAudience(): string
    {
        /** @var string $audience */
        $audience = config('auth.token_audience');

        return $audience;
    }

    /**
     * Returns the time the token is issued at.
     */
    protected function getTokenTimeIssuedAt(): int
    {
        return time();
    }

    /**
     * Returns the time drift i.e. token will be valid not before.
     */
    protected function getTokenTimeNotBefore(): int
    {
        return (time() + config('auth.token_not_before'));
    }

    /**
     * Returns the expiry time for the token.
     */
    protected function getTokenTimeExpiration(): int
    {
        return (time() + config('auth.token_expiration'));
    }

    /**
     * Given a JWT token validate it.
     *
     * @throws RequiredConstraintsViolated
     * @throws NoConstraintsGiven
     */
    public static function validateJwtToken(Token $token): bool
    {
        $config = Jwt::getConfig();

        return $config->validator()->validate(
            $token,
            new IssuedBy(config('auth.token_audience')),
            new SignedWith($config->signer(), $config->verificationKey())
        );
    }
}
