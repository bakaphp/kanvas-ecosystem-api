<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Session;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Exceptions\ValidationException;

/**
 * Exchanges a logged-in CarDashboard session's cookies for its ~1h bearer token. Kept apart from the
 * appointment client because the same token serves every CarDashboard service.
 */
class SessionClient
{
    private const AUTH_URL = 'https://vinsolutions.app.coxautoinc.com/CarDashboard/Services/Auth.ashx';

    public function __construct(
        protected GuzzleClient $client = new GuzzleClient(['timeout' => 30, 'connect_timeout' => 10]),
    ) {
    }

    /**
     * A dead session answers 403, or a redirect to the login page; both surface as a ValidationException
     * so callers can tell "log in again" from a VinSolutions outage, which still throws Guzzle's own.
     *
     * @return array{accessToken: string, expiresIn: string, selectedILMUserID: string}
     */
    public function getToken(string $cookies): array
    {
        try {
            $response = $this->client->get(
                self::AUTH_URL,
                [
                    'headers' => [
                        'Cookie' => $cookies,
                        'Accept' => 'application/json',
                    ],
                    'allow_redirects' => false,
                ]
            );
        } catch (ClientException) {
            $response = null;
        }

        $data = $response === null ? null : json_decode((string) $response->getBody(), true);

        if (! is_array($data) || empty($data['accessToken'])) {
            throw new ValidationException('VinSolutions session is not authenticated');
        }

        return $data;
    }
}
