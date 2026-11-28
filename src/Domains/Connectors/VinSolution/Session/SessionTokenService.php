<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Session;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Redis;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Mints the CarDashboard token from the company's stored session cookies and caches it just under its
 * ~1h life. Throws when the session is gone; the caller decides whether that is a skip or a failure.
 */
class SessionTokenService
{
    private const TOKEN_TTL_SECONDS = 3000;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected SessionClient $client = new SessionClient(),
    ) {
    }

    public function getAccessToken(): string
    {
        $cacheKey = 'vinSolutionSessionToken-' . $this->app->getId() . '-' . $this->company->getId();

        if (($cached = Redis::get($cacheKey)) !== null) {
            return $cached;
        }

        $cookies = (string) $this->company->get(ConfigurationEnum::SESSION_COOKIES->value);

        if ($cookies === '') {
            throw new ValidationException('No VinSolutions session cookies stored for this company');
        }

        $token = $this->client->getToken($cookies);

        Redis::set($cacheKey, $token['accessToken'], 'EX', self::TOKEN_TTL_SECONDS);

        return $token['accessToken'];
    }
}
