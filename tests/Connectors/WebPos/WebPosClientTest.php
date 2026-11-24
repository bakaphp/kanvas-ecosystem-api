<?php

declare(strict_types=1);

namespace Tests\Connectors\WebPos;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\WebPos\Client;
use Kanvas\Connectors\WebPos\Enums\ConfigurationEnum;
use Kanvas\Connectors\WebPos\Enums\EnvironmentEnum;
use Kanvas\Connectors\WebPos\Enums\HostEnum;
use Kanvas\Connectors\WebPos\Exceptions\WebPosRequestException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Tests\Connectors\Traits\HasWebPosConfiguration;
use Tests\TestCase;

final class WebPosClientTest extends TestCase
{
    use DatabaseTransactions;
    use HasWebPosConfiguration;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    public function testTheKeyIsFilledIntoThePathAndNoTokenIsRequested(): void
    {
        Http::fake([self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/checkRnc/*' => Http::response(['razonSocial' => 'Ejemplo SRL'])]);

        $result = new Client($this->webPosCompany())->get(HostEnum::ISSUING, 'api/fedo/ak/v1/checkRnc/' . Client::API_KEY . '/101000001');

        $this->assertSame('Ejemplo SRL', $result['razonSocial']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::WEBPOS_TEST_HOST
            . '/api/fedo/ak/v1/checkRnc/' . self::WEBPOS_API_KEY . '/101000001'
            && ! $request->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/token'));
    }

    public function testAFailureNeverCarriesTheKey(): void
    {
        Http::fake([self::WEBPOS_TEST_HOST . '/*' => Http::response(['message' => 'bad key ' . self::WEBPOS_API_KEY], 400)]);

        try {
            new Client($this->webPosCompany())->post(HostEnum::ISSUING, 'api/fedo/ak/v1/test/sendFileToProcess/LIC/' . Client::API_KEY);
            $this->fail('A 400 must throw.');
        } catch (WebPosRequestException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringNotContainsString(self::WEBPOS_API_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::WEBPOS_API_KEY, $e->body);
            $this->assertStringContainsString(Client::API_KEY, $e->getMessage());
            $this->assertNull($e->getPrevious(), 'a chained Laravel exception would carry the URL');
        }
    }

    public function testAConnectionFailureNeverCarriesTheKey(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for ' . self::WEBPOS_TEST_HOST . '/api/fedo/ak/v1/checkRnc/' . self::WEBPOS_API_KEY . '/1'));

        try {
            new Client($this->webPosCompany())->get(HostEnum::ISSUING, 'api/fedo/ak/v1/checkRnc/' . Client::API_KEY . '/1');
            $this->fail('A connection failure must throw.');
        } catch (WebPosRequestException $e) {
            $this->assertStringNotContainsString(self::WEBPOS_API_KEY, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testA404IsNotFoundNotAnError(): void
    {
        Http::fake([self::WEBPOS_TEST_HOST . '/*' => Http::response([], 404)]);

        $this->assertNull(new Client($this->webPosCompany())->get(HostEnum::ISSUING, 'api/fedo/ak/v1/checkRnc/' . Client::API_KEY . '/1'));
    }

    public function testTheReceptionHostIsApi02(): void
    {
        Http::fake([self::WEBPOS_TEST_RECEPTION_HOST . '/*' => Http::response(['recDocs' => []])]);

        new Client($this->webPosCompany())->get(HostEnum::RECEPTION, 'api/fedo/ak/v1/getRecDoc/LIC/' . Client::API_KEY . '/2026-01-01/2026-01-02');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), self::WEBPOS_TEST_RECEPTION_HOST . '/api/fedo/ak/'));
    }

    public function testProductionUsesTheProductionHost(): void
    {
        Http::fake(['https://fedo-api.webposonline.com/*' => Http::response(['ok' => true])]);
        $company = $this->webPosCompany();
        $company->set(EcfConfigurationEnum::ECF_ENVIRONMENT->value, 'production');

        $client = new Client($company);
        $client->get(HostEnum::ISSUING, 'api/fedo/ak/v1/checkRnc/' . Client::API_KEY . '/1');

        $this->assertSame(EnvironmentEnum::PRODUCTION, $client->environment);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://fedo-api.webposonline.com/'));
    }

    public function testAnEnvironmentPassedInWinsOverTheCompanySetting(): void
    {
        $company = $this->webPosCompany();
        $company->set(EcfConfigurationEnum::ECF_ENVIRONMENT->value, 'production');

        $this->assertSame(EnvironmentEnum::TEST, new Client($company, EnvironmentEnum::TEST)->environment);
    }

    public function testAMissingKeyFailsBeforeAnyCall(): void
    {
        Http::fake();
        $company = $this->webPosCompany();
        $company->del(ConfigurationEnum::API_KEY->value);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('API key is not configured');

        try {
            new Client($company);
        } finally {
            Http::assertNothingSent();
        }
    }
}
