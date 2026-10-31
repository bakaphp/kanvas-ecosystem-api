<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\EchoPay;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\MockClient;
use Tests\TestCase;

final class MockClientContractTest extends TestCase
{
    public function testCheckEnrollmentResponseHydratesConsumerAuthenticationDto(): void
    {
        $app = app(Apps::class);
        $company = Companies::first();

        $mock = new MockClient($app, $company);

        $response = $mock->post(
            ConfigurationEnum::CHECK_PAYER_ENROLLMENT_PATH->value,
            ['payment' => ['clientReferenceInformation' => ['code' => 'test-ref']]],
        );

        $this->assertTrue($response['_mock'] ?? false, 'MockClient must tag responses as _mock');
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('consumerAuthenticationInformation', $response['data']);

        $consumerAuth = $response['data']['consumerAuthenticationInformation'];

        $this->assertIsArray(
            $consumerAuth['strongAuthentication'] ?? null,
            'MockClient::authenticatedConsumerInfo() must return strongAuthentication as array — DTO ConsumerAuthentication::__construct requires array, not string'
        );

        $dto = ConsumerAuthentication::from($consumerAuth);

        $this->assertInstanceOf(ConsumerAuthentication::class, $dto);
        $this->assertIsArray($dto->strongAuthentication);
        $this->assertSame('05', $dto->eci);
        $this->assertNotEmpty($dto->token);
    }

    public function testValidateAuthResultResponseHydratesConsumerAuthenticationDto(): void
    {
        $app = app(Apps::class);
        $company = Companies::first();

        $mock = new MockClient($app, $company);

        $response = $mock->post(
            ConfigurationEnum::VALIDATE_PAYER_AUTH_RESULT_PATH->value,
            ['payment' => ['clientReferenceInformation' => ['code' => 'test-ref']]],
        );

        $consumerAuth = $response['data']['consumerAuthenticationInformation'];

        $this->assertIsArray($consumerAuth['strongAuthentication'] ?? null);

        $dto = ConsumerAuthentication::from($consumerAuth);
        $this->assertInstanceOf(ConsumerAuthentication::class, $dto);
    }

    public function testCheckEnrollmentReturnsAuthenticationSuccessfulStatus(): void
    {
        $app = app(Apps::class);
        $company = Companies::first();

        $mock = new MockClient($app, $company);

        $response = $mock->post(
            ConfigurationEnum::CHECK_PAYER_ENROLLMENT_PATH->value,
            ['payment' => ['clientReferenceInformation' => ['code' => 'test-ref']]],
        );

        $this->assertSame('AUTHENTICATION_SUCCESSFUL', $response['data']['status']);
        $this->assertContains(
            $response['data']['consumerAuthenticationInformation']['eci'] ?? null,
            ['02', '05'],
            'Mock must return a valid ECI (02 or 05) so isValidEci() passes and triggers frictionless approval'
        );
    }
}
