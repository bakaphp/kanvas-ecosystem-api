<?php

declare(strict_types=1);

namespace Kanvas\Auth\Socialite\Drivers;

use Baka\Support\Random;
use Google_Client;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Auth\Socialite\Contracts\DriverInterface;
use Kanvas\Auth\Socialite\DataTransferObject\User;
use Kanvas\Exceptions\ValidationException;

class GoogleDriver implements DriverInterface
{
    protected Google_Client $client;

    public function __construct(protected array $config)
    {
        $this->client = new Google_Client();

        if (! isset($this->config['client_id']) || ! isset($this->config['client_secret']) || ! isset($this->config['redirect_uri'])) {
            throw new ValidationException('Missing google client_id, client_secret or redirect_uri');
        }

        $this->client->setClientId($this->config['client_id']);
        $this->client->setClientSecret($this->config['client_secret']);
        $this->client->setRedirectUri($this->config['redirect_uri']);
    }

    public function getUserFromToken(string $token): User
    {
        $payload = $this->client->verifyIdToken($token);
        if (! $payload) {
            throw new AuthenticationException('Invalid token for google login user');
        }
        $this->client->setAccessToken(
            $token
        );

        return User::from([
            'id' => $payload['sub'],
            'email' => $payload['email'],
            'nickname' => Random::generateDisplayName($payload['name']),
            'name' => $payload['name'],
            'token' => $token,
        ]);
    }
}
