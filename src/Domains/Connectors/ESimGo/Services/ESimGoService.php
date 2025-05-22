<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESimGo\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\ESimGo\Client;
use Kanvas\Exceptions\ValidationException;

class ESimGoService
{
    public function __construct(
        protected Client $client,
        protected AppInterface $app
    ) {
    }

    public function applyEsim(array $options): array
    {
        return $this->client->post('/v2.4/esims/apply', $options);
    }

    public function updateSimDetails(array $options): array
    {
        return $this->client->put('/v2.4/esims', $options);
    }

    public function getEsimDetails($iccid): array
    {
        if (empty($iccid)) {
            throw new ValidationException('ICCID cannot be empty');
        }

        return $this->client->get("/v2.4/esims/{$iccid}");
    }

    public function checkStatus(string $iccid): array
    {
        if (empty($iccid)) {
            throw new ValidationException('ICCID cannot be empty');
        }

        return $this->client->get("/v2.4/esims/{$iccid}");
    }

    public function checkDataStatus(string $iccid, string $bundleName): array
    {
        if (empty($iccid)) {
            throw new ValidationException('ICCID cannot be empty');
        }

        return $this->client->get("/v2.4/esims/{$iccid}/bundles/{$bundleName}");
    }

    public function sendSms(string $iccid, string $message): array
    {
        return $this->client->post("/v2.4/esims/{$iccid}/sms", ['message' => $message]);
    }

    public function checkStatusWithBundle(string $iccid, string $bundleName): array
    {
        $activationData = $this->checkStatus($iccid);
        $dataStatus = $this->checkDataStatus($iccid, $bundleName);

        $esimData = end($dataStatus['assignments']);

        return [
            'status' => $activationData['profileStatus'] ?? 'Unavailable',
            'total' => (int) ($esimData['initialQuantity'] ?? 0),
            'remaining' => (int) ($esimData['remainingQuantity'] ?? 0),
        ];
    }
}
