<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EasyActivation\Services;

use Baka\Contracts\AppInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Connectors\EasyActivation\Client;
use RuntimeException;

class OrderService
{
    protected Client $client;

    public function __construct(AppInterface $app)
    {
        $this->client = new Client($app);
    }

    /**
     * @todo use DTO to create the order
     * @throws GuzzleException
     * @throws RuntimeException
     * @throws Exception
     */
    public function createOrder(array $options = []): array
    {
        $response = $this->client->client->post('/v2/api/place_order', [
            'json' => $options,
        ]);

        $data = json_decode(
            $response->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (isset($data['status']) && $data['status']) {
            return $data;
        }

        if (isset($data['status']) && $data['status'] === 0) {
            $errorMessage = $data['order_response'][0]['error'] ?? 'Other error';

            throw new Exception('Error processing order: ' . $errorMessage);
        }

        throw new RuntimeException('Error processing order.');
    }

    public function checkStatus(string $iccid): array
    {
        $response = $this->client->client->post('/v2/api/checkEsimStatusExpirydate', [
            'form_params' => [
                'iccid' => $iccid,
            ],
        ]);

        $data = json_decode(
            $response->getBody()->getContents(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (isset($data['status']) && $data['status']) {
            return $data;
        }

        if (isset($data['status']) && $data['status'] === 0) {
            throw new Exception($iccid . ' - Error checking esim status.');
        }

        throw new Exception('Error checking esim status.');
    }
}
