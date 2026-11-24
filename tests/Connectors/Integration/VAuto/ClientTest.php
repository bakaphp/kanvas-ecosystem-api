<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VAuto;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VAuto\Client;
use Kanvas\Connectors\VAuto\Exceptions\VAutoException;
use Mockery;
use SoapClient;
use SoapFault;
use SoapHeader;
use Tests\Connectors\Traits\HasVAutoConfiguration;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    use DatabaseTransactions;
    use HasVAutoConfiguration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSignatureIsBase64HmacSha1OverAccessIdAndStamp(): void
    {
        $stamp = '2026-11-02T10:00:00.000000+00:00';

        $this->assertSame(
            base64_encode(hash_hmac('sha1', 'access-1' . $stamp, 'secret-1', true)),
            Client::sign('access-1', 'secret-1', $stamp)
        );
    }

    public function testIdentityHeaderCarriesAccessIdStampAndSignature(): void
    {
        $at = Carbon::parse('2026-11-02 10:00:00.123456', 'UTC');

        $header = new Client($this->vAutoCompany())->identityHeader($at);

        $this->assertInstanceOf(SoapHeader::class, $header);
        $this->assertSame(Client::SOAP_NAMESPACE, $header->namespace);
        $this->assertSame('Identity', $header->name);
        $this->assertSame(self::VAUTO_ACCESS_ID, $header->data['AccessId']);
        $this->assertSame('2026-11-02T10:00:00.123456+00:00', $header->data['AccessStamp']);
        $this->assertSame(
            Client::sign(self::VAUTO_ACCESS_ID, self::VAUTO_SECRET_KEY, $header->data['AccessStamp']),
            $header->data['Signature']
        );
    }

    public function testCreateAppraisalSetsTheIdentityHeaderAndWrapsTheRequest(): void
    {
        $expected = $this->appraisalResponse(77, 'https://vauto.test/77');

        $soap = Mockery::mock(SoapClient::class);
        $soap->shouldReceive('__setSoapHeaders')
            ->once()
            ->with(Mockery::on(fn (array $headers) => count($headers) === 1 && $headers[0] instanceof SoapHeader && $headers[0]->name === 'Identity'))
            ->andReturnTrue();
        $soap->shouldReceive('__soapCall')
            ->once()
            ->with('CreateAppraisal', ['CreateAppraisal' => ['request' => ['Appraisal' => ['Vin' => 'VIN1']]]])
            ->andReturn($expected);

        $client = new Client($this->vAutoCompany(), $soap);

        $this->assertSame($expected, $client->createAppraisal(['Appraisal' => ['Vin' => 'VIN1']]));
        $this->assertSame(self::VAUTO_ENTITY_ID, $client->entityId());
        $this->assertSame(Client::DEFAULT_USER_NAME, $client->userName());
    }

    public function testSoapFaultBecomesAVAutoException(): void
    {
        $soap = Mockery::mock(SoapClient::class);
        $soap->shouldReceive('__setSoapHeaders')->once()->andReturnTrue();
        $soap->shouldReceive('__soapCall')->once()->andThrow(new SoapFault('Client', 'Invalid signature'));

        $this->expectException(VAutoException::class);
        $this->expectExceptionMessage('Invalid signature');

        new Client($this->vAutoCompany(), $soap)->createAppraisal([]);
    }

    public function testMissingCredentialsAreRejectedBeforeAnyCall(): void
    {
        $company = Companies::factory()->create();

        $this->assertFalse(Client::isConfigured($company));
        $this->assertTrue(Client::isConfigured($this->vAutoCompany()));

        $this->expectException(VAutoException::class);

        new Client($company);
    }
}
