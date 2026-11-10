<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl;

use Baka\Contracts\CompanyInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    public function __construct(protected CompanyInterface $company)
    {
    }

    public function rates(array $payload): array
    {
        return $this->request()->post('/rates', $payload)->throw()->json();
    }

    public function createShipment(array $payload, string $messageReference): array
    {
        return $this->request($messageReference)->post('/shipments', $payload)->throw()->json();
    }

    public function tracking(array $trackingNumbers): array
    {
        return $this->request()->get('/tracking', [
            'shipmentTrackingNumber' => implode(',', $trackingNumbers),
            'trackingView' => 'all-checkpoints',
            'levelOfDetail' => 'all',
        ])->throw()->json();
    }

    public function proofOfDelivery(string $trackingNumber): ?array
    {
        $response = $this->request()->get("/shipments/{$trackingNumber}/proof-of-delivery", [
            'shipperAccountNumber' => $this->setting(ConfigurationEnum::ACCOUNT_NUMBER),
            'content' => 'epod-summary-esig',
        ]);

        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->json();
    }

    private function request(?string $messageReference = null): PendingRequest
    {
        $headers = ['x-version' => ConfigurationEnum::API_VERSION];

        if ($messageReference !== null) {
            $headers['Message-Reference'] = $messageReference;
        }

        $baseUrl = (string) ($this->company->get(ConfigurationEnum::BASE_URL->value) ?: ConfigurationEnum::DEFAULT_BASE_URL);

        return Http::baseUrl($baseUrl)
            ->withBasicAuth($this->setting(ConfigurationEnum::USERNAME), $this->setting(ConfigurationEnum::PASSWORD))
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function setting(ConfigurationEnum $key): string
    {
        $value = $this->company->get($key->value);

        if (empty($value)) {
            throw new ValidationException("DHL setting {$key->value} is missing");
        }

        return (string) $value;
    }
}
