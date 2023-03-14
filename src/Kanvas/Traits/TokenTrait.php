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
     *
     * @param string $token
     *
     * @return Token
     */
    protected function getToken(string $token): Token
    {
        $config = Jwt::getConfig();

        return $config->parser()->parse($token);
    }

    /**
     * Returns the default audience for the tokens.
     *
     * @return string
     */
    protected function getTokenAudience(): string
    {
        /** @var string $audience */
        $audience = config('auth.token_audience');

        return $audience;
    }

    /**
     * Returns the time the token is issued at.
     *
     * @return int
     */
    protected function getTokenTimeIssuedAt(): int
    {
        return time();
    }

    /**
     * Returns the time drift i.e. token will be valid not before.
     *
     * @return int
     */
    protected function getTokenTimeNotBefore(): int
    {
        return (time() + config('auth.token_not_before'));
    }

    /**
     * Returns the expiry time for the token.
     *
     * @return int
     */
    protected function getTokenTimeExpiration(): int
    {
        return (time() + config('auth.token_expiration'));
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
