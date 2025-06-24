<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Connectors\QuickBooks\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use QuickBooksOnline\API\DataService\DataService;

class Client
{
    protected DataService $dataService;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->initializeDataService();
    }

    private function initializeDataService(): void
    {
        $clientId = $this->app->get(ConfigurationEnum::QUICKBOOKS_CLIENT_ID->value);
        $clientSecret = $this->app->get(ConfigurationEnum::QUICKBOOKS_CLIENT_SECRET->value);
        $accessToken = $this->app->get(ConfigurationEnum::QUICKBOOKS_ACCESS_TOKEN->value);
        $refreshToken = $this->app->get(ConfigurationEnum::QUICKBOOKS_REFRESH_TOKEN->value);
        $realmId = $this->app->get(ConfigurationEnum::QUICKBOOKS_REALM_ID->value);
        $baseUrl = $this->app->get(ConfigurationEnum::QUICKBOOKS_BASE_URL->value) ?? 'Production';

        if (empty($clientId) || empty($clientSecret) || empty($accessToken) || empty($realmId)) {
            throw new ValidationException('QuickBooks credentials are not properly configured for app: ' . $this->app->name);
        }

        $this->dataService = DataService::Configure([
            'auth_mode' => 'oauth2',
            'ClientID' => $clientId,
            'ClientSecret' => $clientSecret,
            'accessTokenKey' => $accessToken,
            'refreshTokenKey' => $refreshToken,
            'QBORealmID' => $realmId,
            'baseUrl' => $baseUrl,
        ]);

        $this->dataService->throwExceptionOnError(true);

        $this->maybeRefreshTokens();
    }

    private function maybeRefreshTokens(): void
    {
        try {
            $oauthHelper = $this->dataService->getOAuth2LoginHelper();
            $newToken = $oauthHelper->refreshAccessTokenWithRefreshToken(
                $this->app->get(ConfigurationEnum::QUICKBOOKS_REFRESH_TOKEN->value)
            );

            if ($newToken) {
                $this->dataService->updateOAuth2Token($newToken);

                $this->storeUpdatedTokens(
                    $newToken->getAccessToken(),
                    $newToken->getRefreshToken()
                );
            }
        } catch (Exception $e) {
            // You may want to log this
            report($e);

            throw new ValidationException('Unable to refresh QuickBooks tokens: ' . $e->getMessage());
        }
    }

    /**
     * Persist the rotated tokens back to app configuration
     */
    private function storeUpdatedTokens(string $accessToken, string $refreshToken): void
    {
        $this->app->set(ConfigurationEnum::QUICKBOOKS_ACCESS_TOKEN->value, $accessToken);
        $this->app->set(ConfigurationEnum::QUICKBOOKS_REFRESH_TOKEN->value, $refreshToken);
    }

    public function getDataService(): DataService
    {
        return $this->dataService;
    }

    /**
     * Test the QuickBooks connection by fetching company info
     */
    public function testConnection(): array
    {
        try {
            $companyInfo = $this->dataService->getCompanyInfo();
            $error = $this->dataService->getLastError();

            if ($error) {
                return [
                    'success' => false,
                    'message' => 'QuickBooks connection failed: ' . $error->getResponseBody(),
                    'error_code' => $error->getHttpStatusCode(),
                ];
            }

            if (! $companyInfo) {
                return [
                    'success' => false,
                    'message' => 'No company information returned from QuickBooks',
                ];
            }

            return [
                'success' => true,
                'message' => 'Successfully connected to QuickBooks',
                'company_name' => $companyInfo->CompanyName ?? 'Unknown',
                'country' => $companyInfo->Country ?? 'Unknown',
                'realm_id' => $this->app->get(ConfigurationEnum::QUICKBOOKS_REALM_ID->value),
                'base_url' => $this->app->get(ConfigurationEnum::QUICKBOOKS_BASE_URL->value) ?? 'Production',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'QuickBooks connection test failed: ' . $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }

    /**
     * Quick boolean check if connection is working
     */
    public function isConnected(): bool
    {
        try {
            $companyInfo = $this->dataService->getCompanyInfo();
            $error = $this->dataService->getLastError();

            return ! $error && $companyInfo !== null;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get basic company information
     */
    public function getCompanyInfo(): ?array
    {
        try {
            $companyInfo = $this->dataService->getCompanyInfo();
            $error = $this->dataService->getLastError();

            if ($error || ! $companyInfo) {
                return null;
            }

            return [
                'company_name' => $companyInfo->CompanyName ?? 'Unknown',
                'country' => $companyInfo->Country ?? 'Unknown',
                'fiscal_year_start' => $companyInfo->FiscalYearStartMonth ?? 'Unknown',
                'id' => $companyInfo->Id ?? 'Unknown',
                'email' => $companyInfo->Email->Address ?? null,
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Test a simple query to verify API access
     */
    public function testQuery(): array
    {
        try {
            // Simple query to test API access
            $items = $this->dataService->Query('SELECT * FROM Item MAXRESULTS 1');
            $error = $this->dataService->getLastError();

            if ($error) {
                return [
                    'success' => false,
                    'message' => 'Query test failed: ' . $error->getResponseBody(),
                ];
            }

            return [
                'success' => true,
                'message' => 'Query test successful',
                'items_count' => count($items ?? []),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Query test failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate QuickBooks credentials statically
     */
    public static function validateCredentials(
        string $clientId,
        string $clientSecret,
        string $accessToken,
        string $realmId,
        string $baseUrl = 'Production'
    ): bool {
        try {
            $dataService = DataService::Configure([
                'auth_mode' => 'oauth2',
                'ClientID' => $clientId,
                'ClientSecret' => $clientSecret,
                'accessTokenKey' => $accessToken,
                'refreshTokenKey' => '', // Not required for validation
                'QBORealmID' => $realmId,
                'baseUrl' => $baseUrl,
            ]);

            $companyInfo = $dataService->getCompanyInfo();
            $error = $dataService->getLastError();

            return ! $error && $companyInfo !== null;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get configuration status
     */
    public function getConfigurationStatus(): array
    {
        $requiredConfigs = [
            'QUICKBOOKS_CLIENT_ID' => 'Client ID',
            'QUICKBOOKS_CLIENT_SECRET' => 'Client Secret',
            'QUICKBOOKS_ACCESS_TOKEN' => 'Access Token',
            'QUICKBOOKS_REALM_ID' => 'Realm ID',
        ];

        $status = [];
        $allConfigured = true;

        foreach ($requiredConfigs as $key => $name) {
            $value = $this->app->get($key);
            $configured = ! empty($value);

            if (! $configured) {
                $allConfigured = false;
            }

            $status[$key] = [
                'name' => $name,
                'configured' => $configured,
                'preview' => $configured ? substr($value, 0, 8) . '...' : null,
            ];
        }

        return [
            'all_configured' => $allConfigured,
            'base_url' => $this->app->get(ConfigurationEnum::QUICKBOOKS_BASE_URL->value) ?? 'Production',
            'configurations' => $status,
        ];
    }
}
