<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PlateRecognizer;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Kanvas\Connectors\PlateRecognizer\Enums\ConfigurationEnum;

class Client
{
    protected GuzzleClient $client;
    protected string $apiKey;
    protected string $apiUrl = 'https://api.platerecognizer.com/v1/plate-reader/';

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $this->client = new GuzzleClient();
        $this->apiKey = $app->get(ConfigurationEnum::API_KEY->value);

        if (empty($this->apiKey)) {
            throw new InvalidArgumentException('API key is required for Plate Recognizer.');
        }
    }

    /**
     * Recognize license plate from a single image
     *
     * @param string $imagePath File path to the vehicle image
     * @return array|null Raw API response with plate recognition data
     */
    public function recognizePlate(string $imagePath): ?array
    {
        try {
            $response = $this->client->request('POST', $this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Token ' . $this->apiKey,
                ],
                'multipart' => [
                    [
                        'name' => 'upload',
                        'contents' => fopen($imagePath, 'r'),
                    ],
                    [
                        'name' => 'regions',
                        'contents' => $this->app->get(ConfigurationEnum::APP_CAR_REGION->value, 'do'), // Dominican Republic code (update as needed)
                    ],
                    [
                        'name' => 'mmc',
                        'contents' => 'true', // Enable make, model, and color detection
                    ],
                    [
                        'name' => 'config',
                        'contents' => json_encode([
                            'detection_mode' => 'vehicle', // Get vehicles even without plates
                            'detection_rule' => 'strict', // Only detect plates attached to vehicles
                        ]),
                    ],
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            report($e);

            return null;
        }
    }

    /**
     * Check the remaining API usage quota
     *
     * @return array|null API usage statistics
     */
    public function checkUsage(): ?array
    {
        try {
            $response = $this->client->request('GET', 'https://api.platerecognizer.com/v1/statistics/', [
                'headers' => [
                    'Authorization' => 'Token ' . $this->apiKey,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            report($e);

            return null;
        }
    }

    /**
     * Process images with specific region and configuration
     *
     * @param string $imagePath File path to the vehicle image
     * @param string $region Region code (e.g., 'do' for Dominican Republic)
     * @param array $config Additional configuration options
     * @return array|null Raw API response with plate recognition data
     */
    public function processImageWithConfig(string $imagePath, string $region, array $config = []): ?array
    {
        try {
            $multipart = [
                [
                    'name' => 'upload',
                    'contents' => fopen($imagePath, 'r'),
                ],
                [
                    'name' => 'regions',
                    'contents' => $region,
                ],
            ];

            if (! empty($config)) {
                $multipart[] = [
                    'name' => 'config',
                    'contents' => json_encode($config),
                ];
            }

            $response = $this->client->request('POST', $this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Token ' . $this->apiKey,
                ],
                'multipart' => $multipart,
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            report($e);

            return null;
        }
    }
}
