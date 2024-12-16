<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Services;

use Baka\Contracts\AppInterface;
use Exception;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Credit700\Client;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Exceptions\ValidationException;

class CreditScoreService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->client = new Client($app);
    }

    public function getCreditScore(CreditApplicant $creditApplication): array
    {
        try {
            $responseXml = $this->client->post(
                '/Request',
                [
                    'PRODUCT' => 'CREDIT',
                    'BUREAU' => 'XPN', // Can be XPN, TU, or EFX
                    'PASS' => '2',
                    'PROCESS' => 'PCCREDIT',
                    'NAME' => $creditApplication->name,
                    'ADDRESS' => $creditApplication->address,
                    'CITY' => $creditApplication->city,
                    'STATE' => $creditApplication->state,
                    'ZIP' => $creditApplication->zip,
                    'SSN' => $creditApplication->ssn,
                ]
            );

            // Extract and return the credit score data
            return [
                'score' => (string) $responseXml->Scores->Scoring->Score,
                'model' => (string) $responseXml->Scores->Scoring->ScoreModel,
                'factors' => array_map(function ($factor) {
                    return (string) $factor;
                }, (array) $responseXml->Scores->Scoring->children('factor')),
            ];
        } catch (RequestException $e) {
            throw new ValidationException('Failed to retrieve credit score: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new ValidationException('An error occurred: ' . $e->getMessage());
        }
    }

    /**
     * Generate and sign the iFrame URL for accessing the credit report.
     */
    public function generateSignedIframeUrl(string $unsignedUrl, string $signedBy): string
    {
        try {
            // Sign the URL
            return $this->client->signUrl($unsignedUrl, 30, $signedBy); // 30-minute expiration
        } catch (Exception $e) {
            throw new ValidationException('Failed to generate signed URL: ' . $e->getMessage());
        }
    }
}
