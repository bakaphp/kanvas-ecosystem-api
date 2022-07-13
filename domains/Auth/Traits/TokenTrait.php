<?php

declare(strict_types=1);

namespace Kanvas\Auth\Traits;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Kanvas\Auth\Jwt;
use Kanvas\Sessions\Sessions\Models\Sessions;
use Kanvas\Users\Users\Models\Users;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\ValidationData;

trait TokenTrait
{

    /**
     * User variable.
     *
     * @var Users
     */
    protected Users $user;

    /**
     * Returns the string token.
     *
     * @return string
     *
     * @throws ModelException
     */
    public function getToken() : array
    {
        $sessionId = (string)Str::uuid();

        $token = self::createJwtToken($sessionId, $this->user->getEmail());

        $monthInHours = ceil((config('kanvas.jwt.payload.refresh_exp') ?? 2628000) / 3600);
        $refreshToken = self::createJwtToken($sessionId, $this->user->getEmail(), $monthInHours);

        $tokenArray = [
            'sessionId' => $sessionId,
            'token' => $token['token'],
            'refresh_token' => $refreshToken['token'],
            'refresh_token_expiration' => $refreshToken['expiration']->format('Y-m-d H:i:s'),
            'token_expiration' => $token['expiration']->format('Y-m-d H:i:s'),
        ];

        return $this->format($tokenArray);
    }

    /**
     * Given a token format it to the standard response.
     *
     * @param UserInterface $user
     * @param array $token
     *
     * @return array
     */
    public function format(array $token) : array
    {
        return [
            'sessionId' => $token['sessionId'],
            'token' => $token['token'],
            'refresh_token' => $token['refresh_token'],
            'token_expires' => $token['token_expiration'],
            'refresh_token_expires' => $token['refresh_token_expiration'],
            'time' => date('Y-m-d H:i:s'),
            'timezone' => $this->user->timezone,
            'id' => $this->user->id
        ];
    }

    /**
     * Returns the ValidationData object for this record (JWT).
     *
     * @return bool
     *
     * @deprecated 0.2
     */
    public function getValidationData(Token $token) : bool
    {
        return self::validateJwtToken($token);
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
    public static function validateJwtToken(Token $token) : bool
    {
        $config = Jwt::getConfig();

        return $config->validator()->validate(
            $token,
            new IssuedBy(getenv('TOKEN_AUDIENCE')),
            new SignedWith($config->signer(), $config->verificationKey())
        );
    }

    /**
     * Create a new session based off the refresh token session id.
     *
     * @param string $sessionId
     * @param string $email
     *
     * @return array
     */
    public static function createJwtToken(string $sessionId, string $email, float $expirationAt = 0) : array
    {
        $now = new DateTimeImmutable();
        $config = Jwt::getConfig();
        //get the expiration in hours
        $expiration = $expirationAt == 0 ? ceil((config('kanvas.jwt.payload.exp') ?? 604800) / 3600) : $expirationAt;

        //https://lcobucci-jwt.readthedocs.io/en/latest/issuing-tokens/
        $token = $config->builder()
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

        return [
            'sessionId' => $sessionId,
            'token' => $token->toString(),
            'expiration' => $token->claims()->get('exp')
        ];
    }

    /**
     * Get the user Auth Response.
     *
     * @param Users $user
     *
     * @return array
     */
    protected function generateToken(Request $request) : array
    {
        $userIp = $request->ip();
        $pageId = 1;
        $tokenResponse = $this->getToken();

        //start session
        $session = new Sessions();
        $session->start($this->user, $tokenResponse['sessionId'], $tokenResponse['token'], $userIp, $pageId);

        unset($tokenResponse['sessionId']);
        return $tokenResponse;
    }
}
