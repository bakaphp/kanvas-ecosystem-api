<?php

declare(strict_types=1);

namespace Tests\Connectors\PayWay;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PayWay\DataTransferObject\PayWayResponse;
use Kanvas\Connectors\PayWay\Enums\ConfigurationEnum;
use Kanvas\Connectors\PayWay\PayWayClient;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class PayWayClientTest extends TestCase
{
    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();

        $this->currentCompany->set(ConfigurationEnum::PAYWAY_TOKEN->value, 'test-token-abc');
        $this->currentCompany->set(ConfigurationEnum::PAYWAY_COLECTOR_ID->value, '12345');
        $this->currentCompany->set(ConfigurationEnum::PAYWAY_USUARIO_OPERACION->value, 'test-user');
        $this->currentCompany->set(ConfigurationEnum::PAYWAY_BASE_URL->value, 'https://test.payway.sv');
    }

    private function buildMockClient(array $responses, array &$container = []): GuzzleClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($container));

        return new GuzzleClient(['handler' => $handlerStack]);
    }

    public function testAuthInjectedIntoPayUsing3dsRequest(): void
    {
        $container = [];
        $http = $this->buildMockClient([
            new Response(200, [], json_encode([
                'returnCode' => '01',
                'message' => 'Step 1 OK',
                'success' => false,
            ])),
        ], $container);

        $client = new PayWayClient($this->currentApp, $this->currentCompany, $http);
        $client->payUsing3ds(['monto' => '5.00', 'concepto' => 'Test charge']);

        $this->assertCount(1, $container);

        $sentBody = json_decode((string) $container[0]['request']->getBody(), true);

        $this->assertSame('test-token-abc', $sentBody['token']);
        $this->assertSame('test-user', $sentBody['usuarioOperacion']);
        $this->assertSame(12345, $sentBody['colectorId']);
        $this->assertSame('5.00', $sentBody['monto']);
        $this->assertSame('Test charge', $sentBody['concepto']);
    }

    public function testGetTransactionHitsCorrectPath(): void
    {
        $container = [];
        $http = $this->buildMockClient([
            new Response(200, [], json_encode([
                'returnCode' => '00',
                'message' => 'OK',
                'success' => true,
                'numeroCompra' => '1877',
            ])),
        ], $container);

        $client = new PayWayClient($this->currentApp, $this->currentCompany, $http);
        $client->getTransaction('1877');

        $this->assertCount(1, $container);

        /** @var Request $sentRequest */
        $sentRequest = $container[0]['request'];
        $this->assertStringEndsWith('/web-payway-sv/paywayone/api/rest/payments/1877', $sentRequest->getUri()->getPath());
        $this->assertSame('POST', $sentRequest->getMethod());
    }

    public function testVoidTransactionInjectsProvidedTraceId(): void
    {
        $container = [];
        $http = $this->buildMockClient([
            new Response(200, [], json_encode([
                'returnCode' => '00',
                'message' => 'Void OK',
                'success' => true,
                'numeroCompra' => '1877',
            ])),
        ], $container);

        $client = new PayWayClient($this->currentApp, $this->currentCompany, $http);
        $voidTraceId = 'aaaabbbb-cccc-4000-8000-ddddeeeeffff';
        $client->voidTransaction('1877', $voidTraceId);

        $sentBody = json_decode((string) $container[0]['request']->getBody(), true);

        $this->assertSame($voidTraceId, $sentBody['traceId']);
        $this->assertStringEndsWith('/web-payway-sv/paywayone/api/rest/payments/void/1877', $container[0]['request']->getUri()->getPath());
    }

    public function testMissingTokenThrowsValidationException(): void
    {
        $this->currentCompany->set(ConfigurationEnum::PAYWAY_TOKEN->value, '');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/token.*missing|missing.*token/i');

        new PayWayClient($this->currentApp, $this->currentCompany);
    }

    public function testReturnCodePredicatesWork(): void
    {
        $step1 = PayWayResponse::fromArray(['returnCode' => '01', 'message' => '', 'success' => false]);
        $this->assertTrue($step1->is3dsStepRequired());
        $this->assertFalse($step1->isSuccess());
        $this->assertFalse($step1->isDeclined());
        $this->assertFalse($step1->isError());

        $success = PayWayResponse::fromArray(['returnCode' => '00', 'message' => '', 'success' => true]);
        $this->assertTrue($success->isSuccess());

        $declined = PayWayResponse::fromArray(['returnCode' => '02', 'message' => 'Declined', 'success' => false]);
        $this->assertTrue($declined->isDeclined());
        $this->assertFalse($declined->isError());

        $error = PayWayResponse::fromArray(['returnCode' => '99', 'message' => 'System error', 'success' => false]);
        $this->assertTrue($error->isError());
    }

    public function testNetworkFailureProducesTypedResultNotException(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'Connection refused',
                new Request('POST', '/web-payway-sv/paywayone/api/rest/payments/using3ds')
            ),
        ]);
        $http = new GuzzleClient(['handler' => HandlerStack::create($mock)]);

        $client = new PayWayClient($this->currentApp, $this->currentCompany, $http);
        $result = $client->payUsing3ds(['monto' => '5.00']);

        $this->assertInstanceOf(PayWayResponse::class, $result);
        $this->assertSame('98', $result->returnCode);
        $this->assertTrue($result->isError());
        $this->assertNotEmpty($result->message);
    }

    public function testGetTransactionRejectsPathTraversal(): void
    {
        $this->expectException(ValidationException::class);

        $client = new PayWayClient($this->currentApp, $this->currentCompany);
        $client->getTransaction('../../etc/passwd');
    }

    public function testTraceTransactionRejectsNonUuid(): void
    {
        $this->expectException(ValidationException::class);

        $client = new PayWayClient($this->currentApp, $this->currentCompany);
        $client->traceTransaction('not-a-uuid');
    }

    public function testTraceTransactionAcceptsValidUuidV4(): void
    {
        $container = [];
        $http = $this->buildMockClient([
            new Response(200, [], json_encode([
                'returnCode' => '00',
                'message' => 'OK',
                'success' => true,
            ])),
        ], $container);

        $client = new PayWayClient($this->currentApp, $this->currentCompany, $http);
        $validUuid = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
        $result = $client->traceTransaction($validUuid);

        $this->assertSame('00', $result->returnCode);
        $this->assertStringEndsWith(
            '/web-payway-sv/paywayone/api/rest/payments/trace/' . $validUuid,
            $container[0]['request']->getUri()->getPath()
        );
    }
}
