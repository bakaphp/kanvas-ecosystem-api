<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\DataTransferObject;

use Spatie\LaravelData\Data;

class AzulPaymentResponse extends Data
{
    public function __construct(
        public readonly string $authorizationCode = '',
        public readonly string $azulOrderId = '',
        public readonly string $customOrderId = '',
        public readonly string $dateTime = '',
        public readonly string $errorDescription = '',
        public readonly string $isoCode = '',
        public readonly string $lotNumber = '',
        public readonly string $rrn = '',
        public readonly string $responseCode = '',
        public readonly string $responseMessage = '',
        public readonly string $ticket = '',
        public readonly ?string $dataVaultToken = null,
        // DataVault-specific fields
        public readonly string $brand = '',
        public readonly string $maskedCardNumber = '',
        public readonly string $expiration = '',
        public readonly bool $hasCvv = false,
        // 3DS challenge fields (present when IsoCode != '00' and 3DS challenge is required)
        public readonly string $acsUrl = '',
        public readonly string $paReq = '',
        public readonly string $threeDSTransId = '',
        // 3DS v2 method fields (present when IsoCode = '3D2METHOD')
        public readonly string $methodForm = '',
        // Original unmodified response from the Azul API
        public readonly array $raw = [],
    ) {
    }

    public static function fromAzulResponse(array $response): self
    {
        return new self(
            authorizationCode: $response['AuthorizationCode'] ?? '',
            azulOrderId: $response['AzulOrderId'] ?? '',
            customOrderId: $response['CustomOrderId'] ?? '',
            dateTime: $response['DateTime'] ?? '',
            errorDescription: $response['ErrorDescription'] ?? '',
            isoCode: $response['IsoCode'] ?? '',
            lotNumber: $response['LotNumber'] ?? '',
            rrn: $response['RRN'] ?? '',
            responseCode: $response['ResponseCode'] ?? '',
            // Azul has a typo in DataVault responses: "ReponseMessage" (missing 's')
            responseMessage: $response['ResponseMessage'] ?? $response['ReponseMessage'] ?? '',
            ticket: $response['Ticket'] ?? '',
            dataVaultToken: $response['DataVaultToken'] ?? null,
            brand: $response['DataVaultBrand'] ?? $response['Brand'] ?? '',
            maskedCardNumber: $response['CardNumber'] ?? '',
            expiration: $response['DataVaultExpiration'] ?? $response['Expiration'] ?? '',
            hasCvv: (bool) ($response['HasCVV'] ?? false),
            acsUrl: $response['ThreeDSChallenge']['RedirectPostUrl'] ?? $response['ACSUrl'] ?? '',
            paReq: $response['ThreeDSChallenge']['CReq'] ?? $response['ThreeDSChallenge']['PaReq'] ?? $response['PAReq'] ?? $response['CReq'] ?? '',
            threeDSTransId: $response['ThreeDSTransID'] ?? $response['ThreeDSServerTransID'] ?? '',
            methodForm: $response['ThreeDSMethod']['MethodForm'] ?? $response['MethodForm'] ?? '',
            raw: $response,
        );
    }

    public function isApproved(): bool
    {
        return $this->isoCode === '00';
    }

    /**
     * Returns true when Azul requires browser fingerprinting (3DS v2 method step).
     * The frontend must render the MethodForm HTML in a hidden iFrame, then retry.
     */
    public function is3DSMethod(): bool
    {
        return $this->isoCode === '3D2METHOD'
            || $this->responseMessage === '3D_SECURE_2_METHOD'
            || ! empty($this->methodForm);
    }

    /**
     * Returns true when Azul requires a 3DS challenge before completing the transaction.
     * A challenge response includes an ACS URL (redirect for cardholder authentication)
     * but has not yet been approved (IsoCode != '00').
     */
    public function is3DSChallenge(): bool
    {
        return $this->isoCode === '3D'
            || $this->responseMessage === '3D_SECURE_CHALLENGE'
            || ! empty($this->acsUrl);
    }
}
