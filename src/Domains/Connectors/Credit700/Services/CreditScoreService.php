<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Services;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Credit700\Client;
use Kanvas\Connectors\Credit700\DataTransferObject\CreditApplicant;
use Kanvas\Exceptions\ValidationException;

class CreditScoreService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        bool $useProduction = true
    ) {
        $this->client = new Client($app, $useProduction);
    }

    public function getCreditScore(CreditApplicant $creditApplication): array
    {
        try {
            $responseXml = $this->client->post([
                'NAME' => $creditApplication->name,
                'ADDRESS' => $creditApplication->address,
                'CITY' => $creditApplication->city,
                'STATE' => $creditApplication->state,
                'ZIP' => $creditApplication->zip,
                'SSN' => $creditApplication->ssn,
            ]);

            $unsignedUrl = (string)$responseXml->iFrameUrl; // Replace with correct XML tag for iframe URL
            $signedUrl = $this->client->signUrl($unsignedUrl, 30);

            return [
                'score' => (string)$responseXml->Scores->Scoring->Score,
                'model' => (string)$responseXml->Scores->Scoring->ScoreModel,
                'iframeUrl' => $signedUrl,
                'factors' => array_map(function ($factor) {
                    return (string)$factor;
                }, (array)$responseXml->Scores->Scoring->children('factor')),
            ];
        } catch (RequestException $e) {
            throw new ValidationException('Failed to retrieve credit score: ' . $e->getMessage());
        }
    }
}
