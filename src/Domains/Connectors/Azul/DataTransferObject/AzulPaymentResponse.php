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
            brand: $response['Brand'] ?? '',
            maskedCardNumber: $response['CardNumber'] ?? '',
            expiration: $response['Expiration'] ?? '',
            hasCvv: (bool) ($response['HasCVV'] ?? false),
        );
    }

    public function isApproved(): bool
    {
        return $this->isoCode === '00';
    }
}
