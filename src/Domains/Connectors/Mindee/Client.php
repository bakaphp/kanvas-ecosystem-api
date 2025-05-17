<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mindee;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use InvalidArgumentException;
use Mindee\Client as MindeeClient;
use Mindee\Input\PredictMethodOptions;
use Mindee\Product\Generated\GeneratedV1;
use Throwable;

class Client
{
    protected MindeeClient $client;
    protected string $apiKey;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $apiKey = $app->get('OCR_MINDEE_TOKEN');

        if (empty($apiKey)) {
            throw new InvalidArgumentException('API key is required for Mindee.');
        }

        $this->apiKey = $apiKey;
        $this->client = new MindeeClient($this->apiKey);
    }

    /**
     * Process a document using Mindee's API with a custom endpoint
     *
     * @param string $documentType The document type (e.g., 'marbete', 'drivers_license')
     * @param string $filePath Path to the file to be processed
     * @param string $accountName The account name for the custom endpoint
     * @param string $version The version of the custom endpoint
     * @return array|null The parsed document data or null if there was an error
     */
    public function processDocument(
        string $documentType,
        string $filePath,
        string $accountName = 'kaioken',
        string $version = '1'
    ): ?array {
        try {
            // Load a file
            $inputSource = $this->client->sourceFromPath($filePath);

            // If the file is a URL instead of a file path
            if (filter_var($filePath, FILTER_VALIDATE_URL)) {
                $inputSource = $this->client->sourceFromUrl($filePath);
            }

            // Create a custom endpoint
            $customEndpoint = $this->client->createEndpoint(
                $documentType,
                $accountName,
                $version
            );

            // Add the custom endpoint to the prediction options
            $predictOptions = new PredictMethodOptions();
            $predictOptions->setEndpoint($customEndpoint);

            // Parse the file
            $apiResponse = $this->client->enqueueAndParse(GeneratedV1::class, $inputSource, $predictOptions);

            // Convert the document to array
            $responseData = json_decode(json_encode($apiResponse), true);

            return $responseData;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Process document from URL
     *
     * @param string $documentType The document type
     * @param string $fileUrl URL to the file
     * @param string $accountName The account name
     * @param string $version The version
     */
    public function processDocumentFromUrl(
        string $documentType,
        string $fileUrl,
        string $accountName = 'kaioken',
        string $version = '1'
    ): ?array {
        try {
            // Load a file from URL
            $inputSource = $this->client->sourceFromUrl($fileUrl);

            // Create a custom endpoint
            $customEndpoint = $this->client->createEndpoint(
                $documentType,
                $accountName,
                $version
            );

            // Add the custom endpoint to the prediction options
            $predictOptions = new PredictMethodOptions();
            $predictOptions->setEndpoint($customEndpoint);

            // Parse the file
            $apiResponse = $this->client->enqueueAndParse(GeneratedV1::class, $inputSource, $predictOptions);

            // Convert the document to array
            $responseData = json_decode(json_encode($apiResponse), true);

            return $responseData;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
