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

    /**
     * Post (capture) a previously held transaction.
     * Must be called within 7 days of the Hold.
     * Amount may be equal, less, or up to 15% greater than the original Hold amount.
     */
    public function capture(AzulPaymentRequest $request): AzulPaymentResponse
    {
        $payload = [
            'Channel'     => $request->channel,
            'Store'       => $request->store,
            'TrxType'     => TransactionTypeEnum::POST->value,
            'AzulOrderId' => $request->azulOrderId,
            'Amount'      => $request->amount,
            'Itbis'       => $request->itbis,
        ];
        $response = $this->client->post($payload, $this->client->getPostUrl());
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'Post (capture) transaction declined',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    /**
     * Void a Hold or authorized transaction, releasing the reserved funds.
     */
    public function void(AzulPaymentRequest $request): AzulPaymentResponse
    {
        $payload = [
            'Channel'     => $request->channel,
            'Store'       => $request->store,
            'AzulOrderId' => $request->azulOrderId,
        ];
        $response = $this->client->post($payload, $this->client->getVoidUrl());
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'Void transaction declined',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    public function verifyPayment(string $customOrderId): AzulPaymentResponse
    {
        $payload = [
            'Channel'       => $this->channel,
            'Store'         => $this->store,
            'CustomOrderId' => $customOrderId,
        ];

        $response = $this->client->post($payload, $this->client->getVerifyUrl());
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'VerifyPayment failed',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    /**
     * Complete a 3DS challenge by submitting the cRes returned by the ACS.
     * Must be called after the cardholder completes authentication and the ACS
     * POSTs the cRes to the TermUrl.
     */
    public function processThreeDSMethod(
        string $azulOrderId,
        ?string $methodNotificationStatus,
        ?string $cRes = null
    ): AzulPaymentResponse {
        $payload = [
            'Channel'                  => $this->channel,
            'Store'                    => $this->store,
            'AzulOrderId'              => $azulOrderId,
            'MethodNotificationStatus' => $methodNotificationStatus ?? 'RECEIVED',
            ...($cRes ? ['CRes' => $cRes] : []),
        ];


        $response = $this->client->post($payload, $this->client->getThreeDSMethodUrl());
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: '3DS challenge finalization failed',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    /**
     * Initiate a 3DS-enabled Sale.
     * Unlike sale(), this does NOT throw when Azul returns a 3DS challenge —
     * a challenge response (is3DSChallenge()) is a valid intermediate state.
     * Only throws on actual declines.
     */
    public function sale3DS(AzulPaymentRequest $request): AzulPaymentResponse
    {
        $payload  = [...$request->toArray(), 'TrxType' => TransactionTypeEnum::SALE->value];
        $response = $this->client->post($payload);
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved() && ! $result->is3DSMethod() && ! $result->is3DSChallenge()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'Sale transaction declined',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    /**
     * Initiate a 3DS-enabled Hold (pre-authorization).
     * Unlike hold(), this does NOT throw when Azul returns a 3DS challenge.
     */
    public function hold3DS(AzulPaymentRequest $request): AzulPaymentResponse
    {
        $payload  = [...$request->toArray(), 'TrxType' => TransactionTypeEnum::HOLD->value];
        $response = $this->client->post($payload);
        $result   = AzulPaymentResponse::fromAzulResponse($response);

        if (! $result->isApproved() && ! $result->is3DSMethod() && ! $result->is3DSChallenge()) {
            throw new AzulException(
                $result->responseMessage ?: $result->errorDescription ?: 'Hold transaction declined',
                0,
                null,
                $response
            );
        }

        return $result;
    }

    public function refund(AzulPaymentRequest $request): AzulPaymentResponse
    {
        $payload  = [...$request->toArray(), 'TrxType' => TransactionTypeEnum::REFUND->value];
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
