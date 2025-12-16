<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\ChromeData\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use SoapClient;
use SoapFault;

class Client
{
    protected SoapClient $client;
    protected array $accountInfo;
    protected string $wsdlUrl = 'http://services.chromedata.com/Description/7b?wsdl';

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $accountNumber = $this->company?->get(ConfigurationEnum::ACCOUNT_NUMBER->value) ?? $this->app->get(ConfigurationEnum::ACCOUNT_NUMBER->value);
        $accountSecret = $this->company?->get(ConfigurationEnum::ACCOUNT_SECRET->value) ?? $this->app->get(ConfigurationEnum::ACCOUNT_SECRET->value);

        if (empty($accountNumber) || empty($accountSecret)) {
            throw new ValidationException('ChromeData account credentials are missing');
        }

        $this->accountInfo = [
            'number' => $accountNumber,
            'secret' => $accountSecret,
            'country' => $this->company?->get(ConfigurationEnum::COUNTRY->value) ?? $this->app->get(ConfigurationEnum::COUNTRY->value) ?? 'US',
            'language' => $this->company?->get(ConfigurationEnum::LANGUAGE->value) ?? $this->app->get(ConfigurationEnum::LANGUAGE->value) ?? 'en',
        ];

        $wsdlUrl = $this->company?->get(ConfigurationEnum::WSDL_URL->value) ?? $this->app->get(ConfigurationEnum::WSDL_URL->value) ?? $this->wsdlUrl;

        try {
            $this->client = new SoapClient($wsdlUrl, [
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_BOTH,
            ]);
        } catch (SoapFault $e) {
            throw new ValidationException('Failed to initialize ChromeData SOAP client: ' . $e->getMessage());
        }
    }

    /**
     * Describe a vehicle by VIN.
     */
    public function describeVehicleByVin(string $vin, array $switches = []): object
    {
        $request = [
            'accountInfo' => $this->accountInfo,
            'vin' => $vin,
        ];

        if (! empty($switches)) {
            $request['switch'] = $switches;
        }

        return $this->callSoapMethod('describeVehicle', $request);
    }

    /**
     * Describe a vehicle by style ID.
     */
    public function describeVehicleByStyleId(int $styleId, array $switches = []): object
    {
        $request = [
            'accountInfo' => $this->accountInfo,
            'styleId' => $styleId,
        ];

        if (! empty($switches)) {
            $request['switch'] = $switches;
        }

        return $this->callSoapMethod('describeVehicle', $request);
    }

    /**
     * Get available model years.
     */
    public function getModelYears(): object
    {
        return $this->callSoapMethod('getModelYears', [
            'accountInfo' => $this->accountInfo,
        ]);
    }

    /**
     * Get divisions for a specific model year.
     */
    public function getDivisions(int $modelYear): object
    {
        return $this->callSoapMethod('getDivisions', [
            'accountInfo' => $this->accountInfo,
            'modelYear' => $modelYear,
        ]);
    }

    /**
     * Get subdivisions for a specific model year.
     */
    public function getSubdivisions(int $modelYear): object
    {
        return $this->callSoapMethod('getSubdivisions', [
            'accountInfo' => $this->accountInfo,
            'modelYear' => $modelYear,
        ]);
    }

    /**
     * Get models for a specific model year and division/subdivision.
     */
    public function getModels(int $modelYear, ?int $divisionId = null, ?int $subdivisionId = null): object
    {
        $request = [
            'accountInfo' => $this->accountInfo,
            'modelYear' => $modelYear,
        ];

        if ($divisionId !== null) {
            $request['divisionId'] = $divisionId;
        } elseif ($subdivisionId !== null) {
            $request['subdivisionId'] = $subdivisionId;
        }

        return $this->callSoapMethod('getModels', $request);
    }

    /**
     * Get styles for a specific model.
     */
    public function getStyles(int $modelId): object
    {
        return $this->callSoapMethod('getStyles', [
            'accountInfo' => $this->accountInfo,
            'modelId' => $modelId,
        ]);
    }

    /**
     * Get category definitions.
     */
    public function getCategoryDefinitions(): object
    {
        return $this->callSoapMethod('getCategoryDefinitions', [
            'accountInfo' => $this->accountInfo,
        ]);
    }

    /**
     * Get technical specification definitions.
     */
    public function getTechnicalSpecificationDefinitions(): object
    {
        return $this->callSoapMethod('getTechnicalSpecificationDefinitions', [
            'accountInfo' => $this->accountInfo,
        ]);
    }

    /**
     * Call a SOAP method with error handling.
     */
    protected function callSoapMethod(string $method, array $request): object
    {
        try {
            return $this->client->__soapCall($method, [$request]);
        } catch (SoapFault $e) {
            throw new Exception("ChromeData API error ({$method}): " . $e->getMessage(), 0, $e);
        }
    }
}
