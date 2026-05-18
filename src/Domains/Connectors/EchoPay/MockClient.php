<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Contracts\HttpClientInterface;

class MockClient implements HttpClientInterface
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = []
    ) {
    }

    public function get(string $endpoint): array
    {
        $this->logCall('GET', $endpoint);
        $path = $this->stripQuery($endpoint);

        if (str_starts_with($path, ConfigurationEnum::CONSULT_SERVICE_PATH->value)) {
            return $this->ok($this->consultServicePayload());
        }

        return $this->ok([]);
    }

    public function post(string $endpoint, array $data, ?int $timeout = null): array
    {
        $this->logCall('POST', $endpoint, $data);

        return match (true) {
            $endpoint === ConfigurationEnum::AUTHORIZATION_PATH->value => $this->ok(['token' => 'mock-token-' . Str::uuid()->toString()]),
            $endpoint === ConfigurationEnum::CARD_PATH->value => $this->ok($this->cardPayload($data)),
            $endpoint === ConfigurationEnum::SETUP_PAYER_PATH->value => $this->ok($this->setupPayerPayload($data)),
            $endpoint === ConfigurationEnum::CHECK_PAYER_ENROLLMENT_PATH->value => $this->ok($this->checkEnrollmentPayload($data)),
            $endpoint === ConfigurationEnum::VALIDATE_PAYER_AUTH_RESULT_PATH->value => $this->ok($this->validateAuthResultPayload($data)),
            $endpoint === ConfigurationEnum::PAY_SERVICE_PATH->value => $this->ok($this->authorizePayload($data)),
            $endpoint === ConfigurationEnum::AUTHORIZE_PAYMENT_PATH->value => $this->ok($this->authorizePayload($data)),
            $endpoint === ConfigurationEnum::CAPTURE_PAYMENT_PATH->value => $this->ok($this->capturePayload($data)),
            $endpoint === ConfigurationEnum::REVERSAL_PAYMENT_PATH->value => $this->ok($this->reversePayload($data)),
            default => $this->ok([]),
        };
    }

    public function patch(string $endpoint, array $data): array
    {
        $this->logCall('PATCH', $endpoint, $data);

        if (str_starts_with($endpoint, ConfigurationEnum::CARD_PATH->value)) {
            return $this->ok($this->cardUpdatePayload($data));
        }

        return $this->ok([]);
    }

    public function delete(string $endpoint, array $data = []): array
    {
        $this->logCall('DELETE', $endpoint, $data);

        return [
            'message' => 'Card deleted (mock).',
            'statusCode' => 200,
        ];
    }

    protected function ok(array $data): array
    {
        return [
            'data' => $data,
            '_mock' => true,
        ];
    }

    protected function stripQuery(string $endpoint): string
    {
        $pos = strpos($endpoint, '?');

        return $pos === false ? $endpoint : substr($endpoint, 0, $pos);
    }

    protected function logCall(string $method, string $endpoint, array $data = []): void
    {
        Log::info('EchoPay MockClient call', [
            'method' => $method,
            'endpoint' => $endpoint,
            'app' => $this->app->getId(),
            'company' => $this->company->getId(),
            'payload' => $data,
        ]);
    }

    protected function consultServicePayload(): array
    {
        return [
            'serviceCode' => 'MOCK-SVC',
            'contractNumber' => 'MOCK-CONTRACT',
            'invoiceNumber' => 'MOCK-INV-001',
            'invoiceDate' => now()->toDateString(),
            'clientName' => 'Mock Client',
            'currency' => 'DOP',
            'amount' => 100.00,
            'maxAmount' => 100.00,
            'minAmount' => 100.00,
            'chargeId' => 'mock-charge-' . Str::uuid()->toString(),
            'validatorId' => 'mock-validator',
        ];
    }

    protected function cardPayload(array $data): array
    {
        $number = $data['card']['number'] ?? '4111111111111111';

        return [
            'cardNumber' => substr((string) $number, -4),
            'expirationDate' => '2030-12',
            'instrumentIdentifierId' => 'mock-iid-' . Str::uuid()->toString(),
            'paymentInstrumentId' => 'mock-pid-' . Str::uuid()->toString(),
        ];
    }

    protected function cardUpdatePayload(array $data): array
    {
        return [
            'card' => [
                'expirationYear' => $data['card']['expirationYear'] ?? '2030',
                'expirationMonth' => $data['card']['expirationMonth'] ?? '12',
            ],
            'instrumentIdentifierId' => 'mock-iid-' . Str::uuid()->toString(),
            'paymentInstrumentId' => 'mock-pid-' . Str::uuid()->toString(),
        ];
    }

    protected function setupPayerPayload(array $data): array
    {
        return [
            'clientReferenceInformation' => [
                'code' => $data['payment']['clientReferenceInformation']['code'] ?? 'mock-ref',
            ],
            'consumerAuthenticationInformation' => [
                'accessToken' => 'mock-access-token',
                'deviceDataCollectionUrl' => 'https://mock.echopay.local/3ds/collect',
                'referenceId' => 'mock-ref-' . Str::uuid()->toString(),
                'token' => 'mock-token-' . Str::uuid()->toString(),
            ],
            'id' => 'mock-setup-' . Str::uuid()->toString(),
            'status' => 'COMPLETED',
            'submitTimeUtc' => now()->toIso8601String(),
        ];
    }

    protected function checkEnrollmentPayload(array $data): array
    {
        return [
            'clientReferenceInformation' => [
                'code' => $data['payment']['clientReferenceInformation']['code'] ?? 'mock-ref',
            ],
            'consumerAuthenticationInformation' => $this->authenticatedConsumerInfo(),
            'id' => 'mock-enroll-' . Str::uuid()->toString(),
            'paymentInformation' => [
                'card' => [
                    'bin' => '411111',
                    'type' => 'VISA',
                ],
            ],
            'status' => PaymentStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
            'submitTimeUtc' => now()->toIso8601String(),
        ];
    }

    protected function validateAuthResultPayload(array $data): array
    {
        return [
            'clientReferenceInformation' => [
                'code' => $data['payment']['clientReferenceInformation']['code'] ?? 'mock-ref',
            ],
            'consumerAuthenticationInformation' => $this->authenticatedConsumerInfo(),
            'id' => 'mock-validate-' . Str::uuid()->toString(),
            'status' => PaymentStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
        ];
    }

    protected function authorizePayload(array $data): array
    {
        return [
            'id' => 'mock-intent-' . Str::uuid()->toString(),
            'status' => 'AUTHORIZED',
            'processorInformation' => [
                'transactionId' => 'mock-tx-' . Str::uuid()->toString(),
                'approvalCode' => '888888',
                'responseCode' => '00',
            ],
            'clientReferenceInformation' => [
                'code' => $data['payment']['clientReferenceInformation']['code'] ?? 'mock-ref',
            ],
            'submitTimeUtc' => now()->toIso8601String(),
        ];
    }

    protected function capturePayload(array $data): array
    {
        return [
            'id' => 'mock-capture-' . Str::uuid()->toString(),
            'status' => 'PENDING',
            'transactionId' => $data['transactionId'] ?? null,
            'processorInformation' => [
                'transactionId' => $data['transactionId'] ?? ('mock-tx-' . Str::uuid()->toString()),
                'approvalCode' => '888888',
            ],
            'submitTimeUtc' => now()->toIso8601String(),
        ];
    }

    protected function reversePayload(array $data): array
    {
        return [
            'id' => 'mock-reverse-' . Str::uuid()->toString(),
            'status' => 'REVERSED',
            'transactionId' => $data['transactionId'] ?? null,
            'submitTimeUtc' => now()->toIso8601String(),
        ];
    }

    protected function authenticatedConsumerInfo(): array
    {
        return [
            'ecommerceIndicator' => 'vbv',
            'authenticationTransactionId' => 'mock-auth-tx-' . Str::uuid()->toString(),
            'eciRaw' => '05',
            'eci' => '05',
            'authenticationResult' => '0',
            'strongAuthentication' => ['result' => 'YES'],
            'authenticationStatusMsg' => 'Success',
            'accessToken' => 'mock-access-token',
            'token' => 'mock-token',
            'cavv' => 'AAABBBBBCCCCCDDDDD',
            'paresStatus' => 'Y',
            'xid' => 'mock-xid',
            'directoryServerTransactionId' => 'mock-ds-tx',
            'threeDSServerTransactionId' => 'mock-3ds-tx',
            'specificationVersion' => '2.2.0',
            'acsTransactionId' => 'mock-acs-tx',
            'ucafCollectionIndicator' => '',
            'ucafAuthenticationData' => '',
            'challengeRequired' => 'N',
            'acsUrl' => '',
            'acsReferenceNumber' => '',
            'stepUpUrl' => '',
            'pareq' => '',
            'veresEnrolled' => 'Y',
            'acsOperatorID' => '',
        ];
    }
}
