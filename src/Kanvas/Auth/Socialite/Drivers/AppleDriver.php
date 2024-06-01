<?php

declare(strict_types=1);

namespace Kanvas\Auth\Socialite\Drivers;

use Baka\Support\Random;
use GuzzleHttp\Client;
use Kanvas\Auth\Socialite\Contracts\DriverInterface;
use Kanvas\Auth\Socialite\DataTransferObject\User;
use Kanvas\Auth\Socialite\Decoders\ASDecoder;

/**
 * @todo delegate the implementation of to a package
 */
class AppleDriver implements DriverInterface
{
    protected Client $client;
    protected string $clientId;

    public function __construct(protected array $config)
    {
    }

    public function getUserFromToken(string $token): User
    {
        $data = (array) ASDecoder::decodeIdentityToken($token);

        $displayname = Random::generateDisplayName($data['email']);

        return User::from([
            'id' => $data['sub'],
            'email' => $data['email'],
            'nickname' => $displayname,
            'name' => $displayname,
            'token' => $token,
        ]);
    }
}
