<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Services;

use Kanvas\Connectors\CardNet\Client;
use Kanvas\Connectors\CardNet\DataTransferObject\CardDetail;
use Kanvas\Connectors\CardNet\DataTransferObject\CardNetPurchaseRequest;
use Kanvas\Connectors\CardNet\DataTransferObject\CardNetPurchaseResponse;
use Kanvas\Connectors\CardNet\Exceptions\CardNetException;

class CardNetService
{
    public function __construct(
        protected readonly Client $client,
    ) {
    }

    public function createCustomer(
        string $email,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $phoneNumber = null,
        ?string $docNumber = null,
        ?int $documentTypeId = null,
    ): array {
        $body = array_filter(
            [
                'Email' => $email,
                'FirstName' => $firstName,
                'LastName' => $lastName,
                'PhoneNumber' => $phoneNumber,
                'DocNumber' => $docNumber,
                'DocumentTypeId' => $documentTypeId,
            ],
            fn ($value) => $value !== null,
        );

        return $this->client->post('v1/api/customer', $body);
    }

    public function getCustomer(int $customerId): array
    {
        return $this->client->get("v1/api/customer/{$customerId}");
    }

    public function activatePaymentProfile(int $customerId, string $token, string $activationCode): array
    {
        return $this->client->post(
            "v1/api/customer/{$customerId}/activate",
            [
                'Token' => $token,
                'ActivationCode' => $activationCode,
            ],
        );
    }

    public function updatePaymentProfile(
        int $customerId,
        int $paymentProfileId,
        ?string $expiration = null,
        ?bool $enabled = null,
    ): array {
        $body = array_filter(
            [
                'PaymentProfileID' => $paymentProfileId,
                'Expiration' => $expiration,
                'Enable' => $enabled,
            ],
            fn ($value) => $value !== null,
        );

        return $this->client->post(
            "v1/api/Customer/{$customerId}/PaymentProfileUpdate",
            $body,
        );
    }

    public function deletePaymentProfile(int $customerId, int $paymentProfileId): array
    {
        return $this->client->post(
            "v1/api/Customer/{$customerId}/PaymentProfileDelete",
            ['PaymentProfileID' => $paymentProfileId],
        );
    }

    /**
     * Direct tokenization uses a different auth mechanism — the private key is passed
     * as a query param instead of Basic Auth, so we bypass the standard client auth.
     */
    public function tokenizeDirect(CardDetail $cardDetail): array
    {
        return $this->client->postWithQueryKeyAuth(
            'Secure/api/Token',
            $cardDetail->toArray(),
        );
    }

    public function purchase(CardNetPurchaseRequest $request): CardNetPurchaseResponse
    {
        $response = $this->client->post('v1/api/purchase', $request->toArray());
        $result = CardNetPurchaseResponse::fromResponse($response);

        if (! $result->isApproved() && ! $result->isPreauthorized()) {
            throw new CardNetException(
                $result->getResponseMessage() ?: 'Purchase transaction declined',
                0,
                null,
                $response,
            );
        }

        return $result;
    }

    public function hold(CardNetPurchaseRequest $request): CardNetPurchaseResponse
    {
        // Capture must be false for a hold/pre-authorization
        $payload = array_merge($request->toArray(), ['Capture' => false]);
        $response = $this->client->post('v1/api/purchase', $payload);
        $result = CardNetPurchaseResponse::fromResponse($response);

        if (! $result->isApproved() && ! $result->isPreauthorized()) {
            throw new CardNetException(
                $result->getResponseMessage() ?: 'Hold transaction declined',
                0,
                null,
                $response,
            );
        }

        return $result;
    }

    public function commit(int $purchaseId): CardNetPurchaseResponse
    {
        $response = $this->client->post("v1/api/purchase/{$purchaseId}/commit");
        $result = CardNetPurchaseResponse::fromResponse($response);

        if (! $result->isApproved()) {
            throw new CardNetException(
                $result->getResponseMessage() ?: 'Commit transaction declined',
                0,
                null,
                $response,
            );
        }

        return $result;
    }

    public function refund(int $purchaseId): CardNetPurchaseResponse
    {
        $response = $this->client->post("v1/api/purchase/{$purchaseId}/refund");
        $result = CardNetPurchaseResponse::fromResponse($response);

        if (! $result->isApproved()) {
            throw new CardNetException(
                $result->getResponseMessage() ?: 'Refund transaction declined',
                0,
                null,
                $response,
            );
        }

        return $result;
    }

    public function getPurchase(int $purchaseId): CardNetPurchaseResponse
    {
        $response = $this->client->get("v1/api/purchase/{$purchaseId}");

        return CardNetPurchaseResponse::fromResponse($response);
    }

    public function listPurchases(array $filters = []): array
    {
        $allowedFilters = array_filter(
            [
                'From' => $filters['From'] ?? null,
                'To' => $filters['To'] ?? null,
                'CustomerID' => $filters['CustomerID'] ?? null,
                'PaymentMediaId' => $filters['PaymentMediaId'] ?? null,
                'Authorized' => $filters['Authorized'] ?? null,
                'OrderNumber' => $filters['OrderNumber'] ?? null,
            ],
            fn ($value) => $value !== null,
        );

        return $this->client->get('v1/api/purchase', $allowedFilters);
    }
}
