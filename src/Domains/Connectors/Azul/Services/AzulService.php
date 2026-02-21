<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Azul\Client;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentRequest;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentResponse;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Enums\TransactionTypeEnum;
use Kanvas\Connectors\Azul\Exceptions\AzulException;

class AzulService
{
    protected Client $client;
    protected string $store;
    protected string $channel;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = []
    ) {
        $this->client  = new Client($app, $company, $config);
        $this->store   = (string) ($this->app->get(ConfigurationEnum::AZUL_STORE->value)   ?? $config['store']   ?? '');
        $this->channel = (string) ($this->app->get(ConfigurationEnum::AZUL_CHANNEL->value) ?? $config['channel'] ?? '');
    }

    public function processDataVault(
        string $cardNumber,
        string $expiration,
        ?string $cvc = null
    ): AzulPaymentResponse {
        $payload = [
            'Channel'    => $this->channel,
            'Store'      => $this->store,
            'CardNumber' => $cardNumber,
            'Expiration' => $expiration,
            'TrxType'    => TransactionTypeEnum::CREATE->value,
        ];

        if ($cvc !== null) {
            $payload['CVC'] = $cvc;
        }

        $response = $this->client->post($payload, $this->client->getDataVaultUrl());
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'DataVault tokenization declined',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    public function deleteDataVault(string $dataVaultToken): AzulPaymentResponse
    {
        $payload = [
            'Channel'        => $this->channel,
            'Store'          => $this->store,
            'DataVaultToken' => $dataVaultToken,
            'TrxType'        => TransactionTypeEnum::DELETE->value,
        ];

        $response = $this->client->post($payload, $this->client->getDataVaultUrl());
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'DataVault deletion declined',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    public function sale(AzulPaymentRequest $request): AzulPaymentResponse
    {
        $payload  = [...$request->toArray(), 'TrxType' => TransactionTypeEnum::SALE->value];
        $response = $this->client->post($payload);
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'Sale transaction declined',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    public function hold(AzulPaymentRequest $request): AzulPaymentResponse
    {
        $payload  = [...$request->toArray(), 'TrxType' => TransactionTypeEnum::HOLD->value];
        $response = $this->client->post($payload);
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'Hold transaction declined',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    public function refund(
        string $azulOrderId,
        string $amount,
        string $itbis,
        string $customOrderId = ''
    ): AzulPaymentResponse {
        $payload = [
            'Channel'         => $this->channel,
            'Store'           => $this->store,
            'TrxType'         => TransactionTypeEnum::REFUND->value,
            'AzulOrderId'     => $azulOrderId,
            'Amount'          => $amount,
            'Itbis'           => $itbis,
            'CurrencyPosCode' => '$',
            'Payments'        => '1',
            'Plan'            => '0',
            'AcquirerRefData' => '1',
            'CustomOrderId'   => $customOrderId,
            'PosInputMode'    => 'E-Commerce',
        ];

        $response = $this->client->post($payload);
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'Refund transaction declined',
                0,
                null,
                $response
            );
        }

        return $result;
    }
}
