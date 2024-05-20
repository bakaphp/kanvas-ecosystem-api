<?php

declare(strict_types=1);

namespace Kanvas\Auth\Traits;

use DateTimeInterface;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Jwt;
use Kanvas\Auth\NewAccessToken;
use Kanvas\Sessions\Models\Sessions;
use Lcobucci\JWT\Token;

trait HasJwtToken
{
    /**
     * Create a new personal access token for the user.
     *
     * @param  string  $name
     * @param  array  $abilities
     * @param  \DateTimeInterface|null  $expiresAt
     *
     * @return NewAccessToken
     */
    public function createToken(
        string $name,
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null,
        ?string $deviceId = null
    ): NewAccessToken {
        $userIp = request()->ip();
        $pageId = 1;

        $sessionId = (string)Str::uuid();
        $tokenResponse = Jwt::createToken($sessionId, $this->email, 0, $deviceId);
        $monthInHours = ceil((config('kanvas.jwt.payload.refresh_exp') ?? 2628000) / 3600);
        $refreshToken = Jwt::createToken($sessionId, $this->email, $monthInHours, $deviceId);

        //start session
        $session = new Sessions();
        $sessionJwtToken = $session->start(
            $this,
            $name,
            $sessionId,
            $tokenResponse,
            $refreshToken,
            $userIp,
            app(Apps::class),
            $abilities,
            $pageId,
        );

        return new NewAccessToken(
            $sessionJwtToken
        );
    }
}
