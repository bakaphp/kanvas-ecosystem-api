<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class Client
{
    protected GuzzleClient $client;
    public int $dealerId;
    public int $userId;
    protected string $authBaseUrl = 'https://authentication.vinsolutions.com';
    protected string $baseUrl = 'https://api.vinsolutions.com';
    protected string $eventBaseUrl = 'https://api.coxautoinc.com';
    protected string $grantType = 'client_credentials';
    protected string $scope = 'PublicAPI';
    protected string $clientId;
    protected string $clientSecret;
    protected string $apiKey;
    protected string $apiKeyDigitalShowRoom;
    protected string $redisKey = 'vinSolutionAuthToken';
    protected bool $useDigitalShowRoomKey = false;

    public function __construct(int $dealerId, int $userId, ?AppInterface $app = null)
    {
        $app = $app ?? app(Apps::class);
        $this->dealerId = $dealerId;
        $this->userId = $userId;

        if (app()->environment() !== 'production') {
            $this->baseUrl = 'https://sandbox.api.vinsolutions.com';
            $this->eventBaseUrl = 'https://sandbox.api.coxautoinc.com';
        }

        $this->clientId = $app->get(ConfigurationEnum::CLIENT_ID->value);
        $this->clientSecret = $app->get(ConfigurationEnum::CLIENT_SECRET->value);
        $this->apiKey = $app->get(ConfigurationEnum::API_KEY->value);
        $this->apiKeyDigitalShowRoom = $app->get(ConfigurationEnum::API_KEY_DIGITAL_SHOWROOM->value);

        if (! $this->clientId || ! $this->clientSecret || ! $this->apiKey) {
            throw new ValidationException('VinSolutions API keys not set');
        }

        $this->redisKey .= '-v3-' . $app->getId();
        $this->client = new GuzzleClient(
            [
                'base_uri' => $this->baseUrl,
                'timeout' => 30,
                'connect_timeout' => 10,
                'handler' => self::buildHandlerStack(),
                // Force TLS 1.2 via Guzzle's managed option; the raw
                // CURLOPT_SSLVERSION curl option is deprecated in guzzle 7.11+.
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            ]
        );
    }

    /**
     * Guzzle stack with retry-on-transient-failure.
     *
     * VinSolutions intermittently resets connections mid-request during bulk
     * pulls (cURL 35 "Recv failure: Connection reset by peer") and occasionally
     * returns 429/5xx. Those are transient, so retry with exponential backoff
     * instead of letting them bubble up and abort a lead pull / flood Sentry.
     *
     * A base handler can be injected to exercise the retry policy in tests.
     */
    public static function buildHandlerStack(?callable $handler = null): HandlerStack
    {
        $stack = $handler !== null ? HandlerStack::create($handler) : HandlerStack::create();

        /** @var callable(callable):callable $retry */
        $retry = Middleware::retry(
            self::shouldRetry(...),
            static fn (int $retries): int => 500 * (2 ** $retries)
        );

        $stack->push($retry);

        return $stack;
    }

    /**
     * Retry decider for the Guzzle retry middleware.
     *
     * Signature is fixed by Guzzle — $request is passed positionally before
     * $response/$exception, so it stays in the list even though it's unused.
     */
    protected static function shouldRetry(
        int $retries,
        Request $request,
        ?ResponseInterface $response = null,
        ?Throwable $exception = null
    ): bool {
        if ($retries >= 3) {
            return false;
        }

        if ($exception instanceof ConnectException) {
            return true;
        }

        return $response !== null && in_array($response->getStatusCode(), [429, 500, 502, 503, 504], true);
    }

    /**
     * Use digital showroom key.
     */
    public function useDigitalShowRoomKey(): void
    {
        $this->useDigitalShowRoomKey = true;
    }

    /**
     * Authenticate with the VinSolutions API.
     */
    public function auth(): array
    {
        if (($token = Redis::get($this->redisKey)) === null) {
            $response = $this->client->post(
                $this->authBaseUrl . '/connect/token',
                [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'form_params' => [
                        'grant_type' => $this->grantType,
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'scope' => $this->scope,
                    ],
                ]
            );

            $token = $response->getBody()->getContents();

            //set the token in redis
            Redis::set(
                $this->redisKey,
                $token,
                'EX',
                1800
            );
        }

        return json_decode($token, true);
    }

    protected function setHeaders(array $headers): array
    {
        $headers['headers']['api_key'] = ! $this->useDigitalShowRoomKey ? $this->apiKey : $this->apiKeyDigitalShowRoom;
        $headers['headers']['Authorization'] = 'Bearer ' . $this->auth()['access_token'];

        return $headers;
    }

    public function get(string $path, array $params = []): array
    {
        $response = $this->client->get(
            $path,
            $this->setHeaders($params)
        );

        return json_decode(
            $response->getBody()->getContents(),
            true
        );
    }

    public function post(string $path, string $json, array $params = []): array
    {
        $params = $this->setHeaders($params);
        if (! isset($params['headers']['Content-Type'])) {
            $params['headers']['Content-Type'] = 'application/json';
        }

        $params['body'] = $json;

        $response = $this->client->post(
            $path,
            $params
        );

        return json_decode(
            $response->getBody()->getContents(),
            true
        );
    }

    public function put(string $path, string $json, array $params = []): array
    {
        $params = $this->setHeaders($params);
        if (! isset($params['headers']['Content-Type'])) {
            $params['headers']['Content-Type'] = 'application/json';
        }

        $params['body'] = $json;

        $response = $this->client->put(
            $path,
            $params
        );

        return ! empty($response->getBody()->getContents()) ? json_decode(
            $response->getBody()->getContents(),
            true
        ) : [];
    }
}
