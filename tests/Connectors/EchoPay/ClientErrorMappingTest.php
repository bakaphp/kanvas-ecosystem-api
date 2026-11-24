<?php

declare(strict_types=1);

namespace Tests\Connectors\EchoPay;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Client;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ClientErrorMappingTest extends TestCase
{
    public static function verbs(): array
    {
        return [
            'get' => [fn (Client $client): array => $client->get('/x')],
            'post' => [fn (Client $client): array => $client->post('/x', [])],
            'patch' => [fn (Client $client): array => $client->patch('/x', [])],
            'delete' => [fn (Client $client): array => $client->delete('/x')],
        ];
    }

    #[DataProvider('verbs')]
    public function testAnErrorResponseCarriesItsStatusAndBody(callable $call): void
    {
        $body = json_encode([
            'statusCode' => 500,
            'message' => 'Internal server error',
        ]);

        try {
            $call($this->clientAnswering(new Response(500, [], $body)));
            $this->fail('Expected an EchoPayException');
        } catch (EchoPayException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('Internal server error', $e->getMessage());
            $this->assertSame('Internal server error', $e->getErrorBody()['message']);
            $this->assertTrue($e->isGatewayFailure());
        }
    }

    #[DataProvider('verbs')]
    public function testAFailureWithNoResponseIsAGatewayFailure(callable $call): void
    {
        $failure = new RequestException('Connection reset', new Request('GET', '/x'));

        try {
            $call($this->clientAnswering($failure));
            $this->fail('Expected an EchoPayException');
        } catch (EchoPayException $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertSame([], $e->getErrorBody());
            $this->assertTrue($e->isGatewayFailure());
        }
    }

    #[DataProvider('verbs')]
    public function testAnErrorBodyThatIsNotJsonStillMapsToAnEchoPayException(callable $call): void
    {
        try {
            $call($this->clientAnswering(new Response(502, [], '<html>Bad Gateway</html>')));
            $this->fail('Expected an EchoPayException');
        } catch (EchoPayException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame([], $e->getErrorBody());
        }
    }

    private function clientAnswering(Response|RequestException $answer): Client
    {
        $company = Auth::user()->getCurrentCompany();
        $credentials = [
            'client_id' => 'id',
            'secret' => 'secret',
        ];

        $client = new class (app(Apps::class), $company, $credentials) extends Client {
            #[Override]
            public function getAccessToken(): string
            {
                return 'token';
            }

            public function answerWith(Response|RequestException $answer): void
            {
                $this->client = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([$answer]))]);
            }
        };

        $client->answerWith($answer);

        return $client;
    }
}
