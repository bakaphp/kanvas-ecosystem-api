<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Client;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Mockery;
use Tests\TestCase;

class AcumaticaWriteFoundationTest extends TestCase
{
    use DatabaseTransactions;

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function enableWrites(): void
    {
        $this->app()->set(ConfigurationEnum::ACUMATICA_WRITE_ENABLED->value, '1');
    }

    public function testPayloadWrapDropsNullsAndWrapsValues(): void
    {
        $wrapped = AcumaticaPayload::wrap([
            'Vendor' => 'GLOBEX',
            'Description' => 'March materials',
            'Amount' => 800.0,
            'Skip' => null,
        ]);

        $this->assertSame(['value' => 'GLOBEX'], $wrapped['Vendor']);
        $this->assertSame(['value' => 800.0], $wrapped['Amount']);
        $this->assertArrayNotHasKey('Skip', $wrapped);
    }

    public function testPayloadValueAndRecordId(): void
    {
        $record = ['id' => 'GUID-1', 'RefNbr' => ['value' => '000123']];

        $this->assertSame('000123', AcumaticaPayload::value($record, 'RefNbr'));
        $this->assertSame('fallback', AcumaticaPayload::value($record, 'Missing', 'fallback'));
        $this->assertSame('GUID-1', AcumaticaPayload::recordId($record));
        $this->assertNull(AcumaticaPayload::recordId(['RefNbr' => ['value' => 'x']]));
    }

    public function testPushThrowsWhenWriteDisabled(): void
    {
        $this->app()->set(ConfigurationEnum::ACUMATICA_WRITE_ENABLED->value, '0');
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('login');

        $service = new AcumaticaWriteService($this->app(), $client);

        $this->expectException(AcumaticaWriteException::class);
        $this->expectExceptionMessage('write-back is disabled');

        $service->push('Bill', AcumaticaPayload::wrap(['Vendor' => 'GLOBEX']));
    }

    public function testPushCreatesRecordInOneSession(): void
    {
        $this->enableWrites();
        $body = AcumaticaPayload::wrap(['Vendor' => 'GLOBEX', 'Description' => 'materials']);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('login')->once();
        $client->shouldReceive('put')->once()->with('Bill', $body)
            ->andReturn(['id' => 'GUID-1', 'RefNbr' => ['value' => '000123']]);
        $client->shouldReceive('invokeAction')->never();
        $client->shouldReceive('logout')->once();

        $record = new AcumaticaWriteService($this->app(), $client)->push('Bill', $body);

        $this->assertSame('GUID-1', AcumaticaPayload::recordId($record));
        $this->assertSame('000123', AcumaticaPayload::value($record, 'RefNbr'));
    }

    public function testFilesPutHrefHandlesStringAndObjectForms(): void
    {
        $this->assertSame(
            '/entity/Bill/000123/files/{filename}',
            AcumaticaPayload::filesPutHref(['_links' => ['files:put' => '/entity/Bill/000123/files/{filename}']]),
        );
        $this->assertSame(
            '/entity/Bill/000123/files/{filename}',
            AcumaticaPayload::filesPutHref(['_links' => ['files:put' => ['href' => '/entity/Bill/000123/files/{filename}']]]),
        );
        $this->assertNull(AcumaticaPayload::filesPutHref(['id' => 'GUID-1']));
    }

    public function testPushAttachesFilesToRecordBeforeRelease(): void
    {
        $this->enableWrites();
        $body = AcumaticaPayload::wrap(['Vendor' => 'GLOBEX']);
        $record = [
            'id' => 'GUID-9',
            '_links' => ['files:put' => '/entity/Default/24.200.001/Bill/Bill/000123/files/{filename}'],
        ];

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('login')->once();
        $client->shouldReceive('put')->once()->andReturn($record);
        $client->shouldReceive('putFile')->once()
            ->with('/entity/Default/24.200.001/Bill/Bill/000123/files/invoice.pdf', 'PDFBYTES', 'application/pdf')
            ->andReturn(204);
        $client->shouldReceive('invokeAction')->once()->andReturn(202);
        $client->shouldReceive('logout')->once();

        new AcumaticaWriteService($this->app(), $client)->push(
            'Bill',
            $body,
            release: true,
            files: [['name' => 'invoice.pdf', 'content' => 'PDFBYTES', 'type' => 'application/pdf']],
        );

        $this->assertTrue(true);
    }

    public function testFindOrCreateReturnsExistingMatch(): void
    {
        $this->enableWrites();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('login')->once();
        $client->shouldReceive('get')->once()->with('Vendor', Mockery::type('array'))
            ->andReturn([['id' => 'GUID-V', 'VendorID' => ['value' => 'V0001']]]);
        $client->shouldReceive('put')->never();
        $client->shouldReceive('logout')->once();

        $record = new AcumaticaWriteService($this->app(), $client)->findOrCreate(
            'Vendor',
            ['$filter' => "VendorName eq 'Globex'"],
            AcumaticaPayload::wrap(['VendorName' => 'Globex']),
        );

        $this->assertSame('V0001', AcumaticaPayload::value($record, 'VendorID'));
    }

    public function testFindOrCreateCreatesWhenNoMatch(): void
    {
        $this->enableWrites();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('login')->once();
        $client->shouldReceive('get')->once()->andReturn([]);
        $client->shouldReceive('put')->once()->with('Vendor', Mockery::type('array'))
            ->andReturn(['id' => 'GUID-NEW', 'VendorID' => ['value' => 'V9999']]);
        $client->shouldReceive('logout')->once();

        $record = new AcumaticaWriteService($this->app(), $client)->findOrCreate(
            'Vendor',
            ['$filter' => "VendorName eq 'NewCo'"],
            AcumaticaPayload::wrap(['VendorName' => 'NewCo']),
        );

        $this->assertSame('V9999', AcumaticaPayload::value($record, 'VendorID'));
    }

    public function testFindOrCreateThrowsWhenWriteDisabled(): void
    {
        $this->app()->set(ConfigurationEnum::ACUMATICA_WRITE_ENABLED->value, '0');
        $client = Mockery::mock(Client::class);
        $client->shouldNotReceive('login');

        $this->expectException(AcumaticaWriteException::class);

        new AcumaticaWriteService($this->app(), $client)->findOrCreate('Vendor', [], []);
    }

    public function testPushAdoptsExistingRecordWhenFindQueryMatchesAndSkipsCreate(): void
    {
        $this->enableWrites();

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('login')->once();
        $client->shouldReceive('get')->once()->with('Bill', Mockery::type('array'))
            ->andReturn([['id' => 'EXISTING-GUID', 'ReferenceNbr' => ['value' => '000777']]]);
        $client->shouldReceive('put')->never();          // adopted — never re-create
        $client->shouldReceive('invokeAction')->never(); // never re-release
        $client->shouldReceive('logout')->once();

        $record = new AcumaticaWriteService($this->app(), $client)->push(
            'Bill',
            AcumaticaPayload::wrap(['Vendor' => 'V1']),
            release: true,
            findQuery: ['$filter' => "VendorRef eq 'INV-1'", '$top' => 1],
        );

        $this->assertSame('EXISTING-GUID', AcumaticaPayload::recordId($record));
    }

    public function testPushReleasesWhenRequested(): void
    {
        $this->enableWrites();
        $body = AcumaticaPayload::wrap(['Vendor' => 'GLOBEX']);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('login')->once();
        $client->shouldReceive('put')->once()->andReturn(['id' => 'GUID-9']);
        $client->shouldReceive('invokeAction')->once()
            ->with('Bill', 'Release', ['entity' => ['id' => 'GUID-9']])
            ->andReturn(202);
        $client->shouldReceive('logout')->once();

        new AcumaticaWriteService($this->app(), $client)->push('Bill', $body, release: true);

        $this->assertTrue(true);
    }

    public function testPushSurfacesAcumaticaErrorBodyAndLogsOut(): void
    {
        $this->enableWrites();
        $response = new Response(422, [], (string) json_encode(['exceptionMessage' => 'Vendor is required.']));
        $request = new Request('PUT', 'entity/Default/24.200.001/Bill');

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('login')->once();
        $client->shouldReceive('put')->once()->andThrow(new RequestException('Client error', $request, $response));
        $client->shouldReceive('logout')->once();

        $this->expectException(AcumaticaWriteException::class);
        $this->expectExceptionMessage('Vendor is required.');

        new AcumaticaWriteService($this->app(), $client)->push('Bill', AcumaticaPayload::wrap(['Description' => 'x']));
    }

    public function testPushSurfacesNestedFieldErrorOverGenericTopLevelMessage(): void
    {
        $this->enableWrites();
        $body = [
            'error' => "Inserting  'AP document' record raised at least one error. Please review the errors.",
            'PostPeriod' => ['value' => '082026', 'error' => 'Error: The 08-2026 financial period is inactive in the NZXT US company.'],
            'Details' => [
                ['Account' => ['value' => '71610'], 'Subaccount' => []],
            ],
        ];
        $response = new Response(422, [], (string) json_encode($body));
        $request = new Request('PUT', 'entity/Default/24.200.001/Bill');

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('login')->once();
        $client->shouldReceive('put')->once()->andThrow(new RequestException('Client error', $request, $response));
        $client->shouldReceive('logout')->once();

        $this->expectException(AcumaticaWriteException::class);
        $this->expectExceptionMessage('PostPeriod: Error: The 08-2026 financial period is inactive in the NZXT US company.');

        new AcumaticaWriteService($this->app(), $client)->push('Bill', AcumaticaPayload::wrap(['Description' => 'x']));
    }
}
