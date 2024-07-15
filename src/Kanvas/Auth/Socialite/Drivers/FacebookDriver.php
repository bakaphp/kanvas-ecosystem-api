<?php

declare(strict_types=1);

namespace Kanvas\Auth\Socialite\Drivers;

use Baka\Support\Random;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Kanvas\Auth\Socialite\Contracts\DriverInterface;
use Kanvas\Auth\Socialite\DataTransferObject\User;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;

/**
 * @todo delegate the implementation of to a package
 */
class FacebookDriver implements DriverInterface
{
    protected Client $client;
    protected string $clientId;

    public function __construct(protected array $config)
    {
        $this->client = new Client();
        $this->clientId = $config['client_id'] ?? throw new AuthenticationException('Facebook Client Id configuration is required');
    }

    public function getUserFromToken(string $token): User
    {
        $kid = json_decode(base64_decode(explode('.', $token)[0]), true)['kid'] ?? null;

        if ($kid === null) {
            return null;
        }

        $data = (array) JWT::decode($token, $this->getPublicKeyOfOIDCToken($kid));

        throw_if($data['aud'] !== $this->clientId, new Exception('Token has incorrect audience.'));
        throw_if($data['iss'] !== 'https://www.facebook.com', new Exception('Token has incorrect issuer.'));

        return User::from([
            'id' => $data['sub'],
            'email' => $data['email'],
            'nickname' => Random::generateDisplayName($data['given_name']),
            'name' => $data['name'],
            'token' => $token,
        ]);
    }

    /**
     * from socialite, https://github.com/laravel/socialite/blob/beb3adcff1a6e400955c459d5ad329c0576da3ed/src/Two/FacebookProvider.php#L158
     * cant extend the class because of our current implementation
     */
    protected function getPublicKeyOfOIDCToken(string $kid)
    {
        $response = $this->client->get('https://limited.facebook.com/.well-known/oauth/openid/jwks/');

        $key = Arr::first(json_decode($response->getBody()->getContents(), true)['keys'], function ($key) use ($kid) {
            return $key['kid'] === $kid;
        });

        $key['n'] = new BigInteger(JWT::urlsafeB64Decode($key['n']), 256);
        $key['e'] = new BigInteger(JWT::urlsafeB64Decode($key['e']), 256);

        return new Key((string) RSA::load($key), 'RS256');
    }
}
