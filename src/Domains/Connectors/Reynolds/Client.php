<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Exceptions\ReynoldsException;
use Kanvas\Connectors\Reynolds\Services\SoapEnvelopeBuilder;
use Kanvas\Connectors\Reynolds\Services\XmlParser;

class Client
{
    protected GuzzleClient $client;
    protected string $endpoint;
    protected string $username;
    protected string $password;

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
        $this->endpoint = (string) $company->get(ConfigurationEnum::REYNOLDS_ENDPOINT->value);
        $this->username = (string) $company->get(ConfigurationEnum::REYNOLDS_USERNAME->value);
        $this->password = (string) $company->get(ConfigurationEnum::REYNOLDS_PASSWORD->value);

        if ($this->endpoint === '' || $this->username === '' || $this->password === '') {
            throw new ReynoldsException('Reynolds connector credentials are not configured');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->endpoint,
            'timeout' => 60,
            // Force TLS 1.2 via Guzzle's managed option; the raw
            // CURLOPT_SSLVERSION curl option is deprecated in guzzle 7.11+.
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ]);
    }

    /**
     * Send a synchronous ProcessMessage request (used for Insert/Update Sales Lead).
     */
    public function processMessage(string $rootElement, array $payload, string $soapAction = ''): array
    {
        $envelope = SoapEnvelopeBuilder::buildProcessMessage(
            $rootElement,
            $payload,
            $this->username,
            $this->password
        );

        try {
            $response = $this->client->post($this->endpoint, [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => $soapAction,
                ],
                'body' => $envelope,
            ]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();

            throw new ReynoldsException("Reynolds SOAP request failed: {$body}", $e->getCode(), $e);
        }

        $responseBody = (string) $response->getBody();
        $parsed = XmlParser::extractPayloadFromEnvelope($responseBody);

        $status = $parsed['TransStatus'] ?? null;
        if (is_array($status) && isset($status['Status']) && $status['Status'] === 'Failure') {
            throw new ReynoldsException(
                'Reynolds returned failure status (code ' . ($status['StatusCode'] ?? 'unknown') . ')'
            );
        }

        return $parsed;
    }

    public function getDealerNumber(): string
    {
        return (string) $this->company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value);
    }

    public function getStoreNumber(): string
    {
        return (string) $this->company->get(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value);
    }

    public function getAreaNumber(): string
    {
        return (string) $this->company->get(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value);
    }

    public function getSenderName(): string
    {
        return (string) $this->company->get(ConfigurationEnum::REYNOLDS_SENDER_NAME->value);
    }

    public function getBusinessUnitName(): string
    {
        return (string) $this->company->get(ConfigurationEnum::REYNOLDS_BUSINESS_UNIT_NAME->value);
    }
}
