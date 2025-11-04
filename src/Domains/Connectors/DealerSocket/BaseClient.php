<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DealerSocket\Services\AuthService;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketConfigurationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;

abstract class BaseClient
{
    protected AuthService $authService;
    protected string $baseUrl;

    public function __construct(Companies $company, Apps $app, Regions $region)
    {
        [$publicKey, $privateKey, $username, $password, $dealerId, $vendorName] = self::getKeys($company, $app, $region);
        $this->authService = new AuthService(
            publicKey: $publicKey,
            privateKey: $privateKey,
            directPostUsername: $username,
            directPostPassword: $password,
            dealerId: $dealerId,
            vendorName: $vendorName
        );
        $this->baseUrl = "https://api.dealersocket.com/api/DealerSocket";
    }

    protected function post(string $endpoint, string $xmlBody)
    {
        $headers = $this->authService->getHMACHeaders($xmlBody);

        $response = Http::withHeaders($headers)
            ->withBody($xmlBody, 'application/xml')
            ->post($this->baseUrl . $endpoint);

        return $this->parseResponse($response);
    }

    protected function parseResponse($response)
    {
        if ($response->failed()) {
            throw new Exception("DealerSocket API Error: " . $response->body());
        }

        $contentType = $response->header('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            return json_decode($response->body());
        }

        return simplexml_load_string($response->body());
    }

    public static function getKeys(
        CompanyInterface $company,
        AppInterface $app,
        Regions $region
    ): array {
        $credentialKey = DealerSocketConfigurationService::generateCredentialKey($company, $app, $region);
        $credential = $company->get($credentialKey);

        if (empty($credential) || ! is_array($credential)) {
            throw new ValidationException(
                sprintf(
                    'DealerSocket keys are not set for company %s (ID: %d) on region %s',
                    $company->name,
                    $company->id,
                    $region->name
                )
            );
        }

        return [
            $credential[CustomFieldEnum::DEALER_SOCKET_PUBLIC_KEY->value],
            $credential[CustomFieldEnum::DEALER_SOCKET_PRIVATE_KEY->value],
            $credential[CustomFieldEnum::DEALER_SOCKET_USERNAME->value],
            $credential[CustomFieldEnum::DEALER_SOCKET_PASSWORD->value],
            $credential[CustomFieldEnum::DEALER_SOCKET_DEALER_ID->value],
            $credential[CustomFieldEnum::DEALER_SOCKET_VENDOR_NAME->value],
        ];
    }
}
