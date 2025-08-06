<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\UniversalAssistance\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use SoapClient;
use SoapFault;

class Client
{
    protected ?SoapClient $quoteClient = null;
    protected ?SoapClient $voucherClient = null;
    protected ?SoapClient $queryClient = null;
    protected string $baseUrl;
    protected string $username;
    protected string $password;
    protected string $organization;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $this->username = $this->app->get(ConfigurationEnum::USERNAME->value);
        $this->password = $this->app->get(ConfigurationEnum::PASSWORD->value);
        $this->organization = $this->app->get(ConfigurationEnum::ORGANIZATION->value);

        if (empty($this->baseUrl) || empty($this->username) || empty($this->password)) {
            throw new ValidationException('Universal Assistance configuration is missing');
        }
    }

    /**
     * Get SOAP client for Quote (Lead/Quote) operations
     */
    protected function getQuoteClient(): SoapClient
    {
        if ($this->quoteClient === null) {
            try {
                $wsdlUrl = $this->app->get(ConfigurationEnum::WSDL_QUOTE->value) 
                    ?? $this->baseUrl . '/quote.wsdl';
                
                $this->quoteClient = new SoapClient($wsdlUrl, [
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                ]);
            } catch (SoapFault $e) {
                throw new ValidationException('Failed to create Quote SOAP client: ' . $e->getMessage());
            }
        }

        return $this->quoteClient;
    }

    /**
     * Get SOAP client for Voucher operations
     */
    protected function getVoucherClient(): SoapClient
    {
        if ($this->voucherClient === null) {
            try {
                $wsdlUrl = $this->app->get(ConfigurationEnum::WSDL_VOUCHER->value) 
                    ?? $this->baseUrl . '/voucher.wsdl';
                
                $this->voucherClient = new SoapClient($wsdlUrl, [
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                ]);
            } catch (SoapFault $e) {
                throw new ValidationException('Failed to create Voucher SOAP client: ' . $e->getMessage());
            }
        }

        return $this->voucherClient;
    }

    /**
     * Get SOAP client for Query operations
     */
    protected function getQueryClient(): SoapClient
    {
        if ($this->queryClient === null) {
            try {
                $wsdlUrl = $this->app->get(ConfigurationEnum::WSDL_QUERY->value) 
                    ?? $this->baseUrl . '/query.wsdl';
                
                $this->queryClient = new SoapClient($wsdlUrl, [
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                ]);
            } catch (SoapFault $e) {
                throw new ValidationException('Failed to create Query SOAP client: ' . $e->getMessage());
            }
        }

        return $this->queryClient;
    }

    /**
     * Create or update a lead (quote)
     */
    public function createOrUpdateLead(array $leadData): array
    {
        try {
            $client = $this->getQuoteClient();
            
            $parameters = [
                'Username' => $this->username,
                'Password' => $this->password,
                'OrganizacionEmisora' => $this->organization,
                ...$leadData
            ];

            $response = $client->__soapCall('CreacionLead', [$parameters]);
            
            return $this->parseSoapResponse($response);
        } catch (Exception $e) {
            throw new ValidationException('Failed to create/update lead: ' . $e->getMessage());
        }
    }

    /**
     * Create a voucher
     */
    public function createVoucher(array $voucherData): array
    {
        try {
            $client = $this->getVoucherClient();
            
            $parameters = [
                'Username' => $this->username,
                'Password' => $this->password,
                'OrganizacionEmisora' => $this->organization,
                ...$voucherData
            ];

            $response = $client->__soapCall('AltaVoucher', [$parameters]);
            
            return $this->parseSoapResponse($response);
        } catch (Exception $e) {
            throw new ValidationException('Failed to create voucher: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a lead
     */
    public function cancelLead(string $leadId, string $reasonCode): array
    {
        try {
            $client = $this->getQuoteClient();
            
            $parameters = [
                'Username' => $this->username,
                'Password' => $this->password,
                'OrganizacionEmisora' => $this->organization,
                'IdLead' => $leadId,
                'ReasonCode' => $reasonCode
            ];

            $response = $client->__soapCall('BajaLead', [$parameters]);
            
            return $this->parseSoapResponse($response);
        } catch (Exception $e) {
            throw new ValidationException('Failed to cancel lead: ' . $e->getMessage());
        }
    }

    /**
     * Query voucher information
     */
    public function queryVoucher(array $queryParams): array
    {
        try {
            $client = $this->getQueryClient();
            
            $parameters = [
                'Username' => $this->username,
                'Password' => $this->password,
                'OrganizacionEmisora' => $this->organization,
                ...$queryParams
            ];

            $response = $client->__soapCall('ConsultaVoucherPortal', [$parameters]);
            
            return $this->parseSoapResponse($response);
        } catch (Exception $e) {
            throw new ValidationException('Failed to query voucher: ' . $e->getMessage());
        }
    }

    /**
     * Generate PDF
     */
    public function generatePdf(array $pdfParams): array
    {
        try {
            $client = $this->getQueryClient();
            
            $parameters = [
                'Username' => $this->username,
                'Password' => $this->password,
                'OrganizacionEmisora' => $this->organization,
                ...$pdfParams
            ];

            $response = $client->__soapCall('GeneracionPDF', [$parameters]);
            
            return $this->parseSoapResponse($response);
        } catch (Exception $e) {
            throw new ValidationException('Failed to generate PDF: ' . $e->getMessage());
        }
    }

    /**
     * Parse SOAP response to array
     */
    protected function parseSoapResponse($response): array
    {
        if (is_object($response)) {
            return json_decode(json_encode($response), true);
        }
        
        return (array) $response;
    }

    /**
     * Get the last SOAP request for debugging
     */
    public function getLastRequest(string $clientType = 'quote'): ?string
    {
        switch ($clientType) {
            case 'quote':
                return $this->quoteClient?->__getLastRequest();
            case 'voucher':
                return $this->voucherClient?->__getLastRequest();
            case 'query':
                return $this->queryClient?->__getLastRequest();
            default:
                return null;
        }
    }

    /**
     * Get the last SOAP response for debugging
     */
    public function getLastResponse(string $clientType = 'quote'): ?string
    {
        switch ($clientType) {
            case 'quote':
                return $this->quoteClient?->__getLastResponse();
            case 'voucher':
                return $this->voucherClient?->__getLastResponse();
            case 'query':
                return $this->queryClient?->__getLastResponse();
            default:
                return null;
        }
    }
}
