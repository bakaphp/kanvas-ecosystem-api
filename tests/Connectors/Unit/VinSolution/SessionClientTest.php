<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\VinSolution;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\VinSolution\Session\SessionClient;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SessionClientTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, response: mixed}> */
    private array $recorded = [];

    public function testGetTokenParsesTheAuthResponseAndSendsTheCookies(): void
    {
        $client = $this->clientReturning(new Response(200, [], json_encode([
            'accessToken' => 'jwt-abc',
            'expiresIn' => '3597',
            'selectedILMUserID' => '60020',
        ])));

        $token = $client->getToken('ASP.NET_SessionId=x; .ASPXAUTH=y');
        $request = $this->recorded[0]['request'];

        $this->assertSame('jwt-abc', $token['accessToken']);
        $this->assertSame('ASP.NET_SessionId=x; .ASPXAUTH=y', $request->getHeaderLine('Cookie'));
        $this->assertStringContainsString('Auth.ashx', (string) $request->getUri());
    }

    public function testADeadSessionAnsweringForbiddenIsNotAuthenticated(): void
    {
        $client = $this->clientReturning(new Response(403));

        $this->expectException(ValidationException::class);
        $client->getToken('dead=cookie');
    }

    public function testAnOutageIsNotMistakenForADeadSession(): void
    {
        $client = $this->clientReturning(new Response(503));

        $this->expectException(ServerException::class);
        $client->getToken('live=cookie');
    }

    public function testGetTokenRejectsAnUnauthenticatedSession(): void
    {
        $client = $this->clientReturning(new Response(200, [], json_encode(['loggedIn' => false])));

        $this->expectException(ValidationException::class);
        $client->getToken('dead=cookie');
    }

    private function clientReturning(Response $response): SessionClient
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->recorded));

        return new SessionClient(new GuzzleClient(['handler' => $stack]));
    }
}
