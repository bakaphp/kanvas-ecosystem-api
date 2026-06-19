<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Connectors\SuperCarros\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl;
    protected string $accessKey;
    protected int|string $customerId;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
        // base_url is the shared API endpoint and stays app-level; the access key and
        // customer id identify a single dealer rooftop, so they MUST be read per-company.
        // Reading the access key from the app would let one company's key pull another
        // rooftop's inventory into the wrong customer account.
        $baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $accessKey = $this->company->get(ConfigurationEnum::ACCESS_KEY->value);
        $customerId = $this->company->get(ConfigurationEnum::CUSTOMER_ID->value);

        if (empty($baseUrl) || empty($accessKey) || empty($customerId)) {
            throw new ValidationException('SuperCarros configuration is missing for this company (base_url, access_key and customer_id are required).');
        }

        $this->baseUrl = (string) $baseUrl;
        $this->accessKey = (string) $accessKey;
        $this->customerId = $customerId;

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Get the list of all inventory for a customer.
     */
    public function getVehicleList(): array
    {
        $response = $this->client->get('/ApiDealers/AdsQuery', [
            'query' => [
                'accKey' => $this->accessKey,
                'customer' => $this->customerId,
            ],
        ]);

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ValidationException('Invalid response from SuperCarros API');
        }

        return $data;
    }

    public function setCustomerId(int|string $customerId): void
    {
        $this->customerId = $customerId;
    }

    /**
     * Get vehicle details by vehicle ID.
     */
    public function getVehicleDetails(int $adId): array
    {
        $response = $this->client->get('/ApiDealers/AdsGet', [
            'query' => [
                'accKey' => $this->accessKey,
                'adId' => $adId,
            ],
        ]);

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ValidationException('Invalid response from SuperCarros API');
        }

        return $data;
    }

    /**
     * Validate SuperCarros API credentials.
     */
    public static function validateCredentials(
        string $baseUrl,
        string $accessKey
    ): bool {
        try {
            $client = new GuzzleClient([
                'base_uri' => $baseUrl,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Test the connection with a basic query using a known customer
            $response = $client->get('/ApiDealers/AdsQuery', [
                'query' => [
                    'accKey' => $accessKey,
                    'customer' => 1, // Test with minimal customer ID
                ],
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            // Make sure we got a valid response
            if (! is_array($data)) {
                throw new ValidationException('Invalid response from SuperCarros API');
            }

            return true;
        } catch (GuzzleException $e) {
            throw new ValidationException(
                'Failed to connect to SuperCarros API: ' . $e->getMessage(),
                $e->getCode()
            );
        }
    }
}
