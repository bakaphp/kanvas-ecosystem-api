<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\WaSender;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Sleep;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Connectors\WaSender\Services\MessageService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class MessageServiceRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function testDecryptionRecoversFromServerAndConnectionFailures(): void
    {
        $payload = ['data' => ['messages' => ['key' => ['id' => 'test-media']]]];
        $result = ['publicUrl' => 'https://example.com/media.mp4'];
        $attempts = 0;
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(3))->method('post')
            ->with('/api/decrypt-media', $payload)
            ->willReturnCallback(function () use (&$attempts, $result): array {
                $attempts++;

                return match ($attempts) {
                    1 => throw $this->serverFailure(),
                    2 => throw new ConnectException('Connection failed', new Request('POST', '/api/decrypt-media')),
                    default => $result,
                };
            });

        $this->assertSame($result, $this->service($client)->decryptMediaFile($payload));
        Sleep::assertSleptTimes(2);
    }

    public function testPersistentOutageStopsAfterThreeAttempts(): void
    {
        $failure = $this->serverFailure();
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(3))->method('post')->willThrowException($failure);

        $this->expectExceptionObject($failure);
        $this->service($client)->decryptMediaFile([]);
    }

    public function testProviderRefusalsAndUnexpectedErrorsAreNotRetried(): void
    {
        foreach ([new WaSenderRefusedException('Media too large', 400), new RuntimeException('Unexpected failure')] as $failure) {
            $client = $this->createMock(Client::class);
            $client->expects($this->once())->method('post')->willThrowException($failure);

            try {
                $this->service($client)->decryptMediaFile([]);
                $this->fail('Expected the original failure');
            } catch (Throwable $caught) {
                $this->assertSame($failure, $caught);
            }
        }

        Sleep::assertNeverSlept();
    }

    public function testOutboundMessagesAreNotRetried(): void
    {
        $failure = $this->serverFailure();
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('post')
            ->with('/api/send-message', ['to' => '+18095550123', 'text' => 'Hello'])
            ->willThrowException($failure);

        $this->expectExceptionObject($failure);
        $this->service($client)->sendTextMessage('+18095550123', 'Hello');
    }

    private function serverFailure(): ServerException
    {
        return new ServerException(
            'Service Unavailable',
            new Request('POST', '/api/decrypt-media'),
            new Response(503, [], 'error code: 1102'),
        );
    }

    private function service(Client $client): MessageService
    {
        return new class ($client) extends MessageService {
            public function __construct(Client $client)
            {
                $this->client = $client;
            }
        };
    }
}
