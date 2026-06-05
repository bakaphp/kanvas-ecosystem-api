<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Hermes;

use Kanvas\Connectors\Hermes\Actions\ChatWithAgentAction;
use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ChatWithAgentActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testHappyPathSendsExpectedCurlAndReturnsReplyText(): void
    {
        $ssh = $this->makeSshMock($this->chatBody('hello back'), 200);

        $action = $this->makeAction(
            message: 'hello',
            agentToken: 'tok-agent',
            deploymentToken: null,
            sshClient: $ssh,
        );

        $result = $action->execute();

        $this->assertSame('hello back', $result);

        $command = $ssh->capturedCommand;
        $this->assertStringContainsString('docker cp', $command);
        $this->assertStringContainsString('docker exec', $command);
        $this->assertStringContainsString('hermes-bot', $command);
        $this->assertStringContainsString('curl -sS', $command);
        $this->assertStringContainsString('http://127.0.0.1:8642/v1/chat/completions', $command);
        $this->assertStringContainsString('Authorization: Bearer tok-agent', $command);
        $this->assertStringContainsString('--data-binary @', $command);

        $this->assertStringContainsString('"model":"hermes-agent"', $ssh->capturedPayload);
        $this->assertStringContainsString('"content":"hello"', $ssh->capturedPayload);
    }

    public function testFallsBackToDeploymentTokenWhenAgentHasNone(): void
    {
        $ssh = $this->makeSshMock($this->chatBody('ok'), 200);

        $action = $this->makeAction(
            message: 'hi',
            agentToken: null,
            deploymentToken: 'tok-deployment',
            sshClient: $ssh,
        );

        $action->execute();

        $this->assertStringContainsString('Authorization: Bearer tok-deployment', $ssh->capturedCommand);
    }

    public function testImagesAreSentAsMultimodalContent(): void
    {
        $ssh = $this->makeSshMock($this->chatBody('saw it'), 200);

        // A real 1x1 PNG so finfo can resolve the media type to image/png.
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=',
            true,
        );

        $action = $this->makeAction(
            message: 'look',
            agentToken: 'tok',
            deploymentToken: null,
            sshClient: $ssh,
            images: ['https://cdn.example.com/pic.png'],
            cannedImageBytes: $png,
        );

        $action->execute();

        // Hermes' vision can't fetch remote URLs — image bytes must be inlined as a data: URI.
        $payload = $ssh->capturedPayload;
        $this->assertStringContainsString('"type":"text"', $payload);
        $this->assertStringContainsString('"type":"image_url"', $payload);
        $this->assertStringContainsString('data:image/png;base64,', $payload);
        $this->assertStringNotContainsString('https://cdn.example.com/pic.png', $payload);
    }

    public function testThrowsOnNon2xxHttpError(): void
    {
        $ssh = $this->makeSshMock('{"error":"boom"}', 500);

        $action = $this->makeAction(
            message: 'hi',
            agentToken: 'tok',
            deploymentToken: null,
            sshClient: $ssh,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('HTTP 500');

        $action->execute();
    }

    public function testThrowsWhenResponseBodyIsMalformedJson(): void
    {
        $ssh = $this->makeSshMock('<html>gateway offline</html>', 200);

        $action = $this->makeAction(
            message: 'hi',
            agentToken: 'tok',
            deploymentToken: null,
            sshClient: $ssh,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('non-JSON response');

        $action->execute();
    }

    public function testThrowsWhenResponseHasNoMessageContent(): void
    {
        $ssh = $this->makeSshMock('{"choices":[]}', 200);

        $action = $this->makeAction(
            message: 'hi',
            agentToken: 'tok',
            deploymentToken: null,
            sshClient: $ssh,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no message content');

        $action->execute();
    }

    public function testThrowsWhenNoGatewayTokenAnywhere(): void
    {
        $ssh = new FakeSshClient();

        $action = $this->makeAction(
            message: 'hi',
            agentToken: null,
            deploymentToken: null,
            sshClient: $ssh,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Hermes gateway token not set');

        $action->execute();
    }

    public function testThrowsWhenAgentHasNoActiveDeployment(): void
    {
        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('getAttribute')->with('activeDeployment')->andReturn(null);

        $action = new ChatWithAgentAction($agent, 'hi');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('active Hermes deployment');

        $action->execute();
    }

    /**
     * @param list<string> $images
     */
    private function makeAction(
        string $message,
        ?string $agentToken,
        ?string $deploymentToken,
        FakeSshClient $sshClient,
        array $images = [],
        string $cannedImageBytes = '',
    ): TestableChatWithAgentAction {
        $machine = Mockery::mock(AgentMachine::class);

        $deployment = Mockery::mock(AgentDeployment::class)->makePartial();
        $deployment->shouldReceive('isRunning')->andReturn(true);
        $deployment->shouldReceive('get')
            ->with(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value)
            ->andReturn($deploymentToken);
        $deployment->shouldReceive('getAttribute')->with('machine')->andReturn($machine);
        $deployment->shouldReceive('getAttribute')->with('container_name')->andReturn('hermes-bot');

        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('getAttribute')->with('activeDeployment')->andReturn($deployment);
        $agent->shouldReceive('get')
            ->with(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value)
            ->andReturn($agentToken);
        $agent->shouldReceive('getId')->andReturn(940);

        return new TestableChatWithAgentAction($agent, $message, $images, $sshClient, $cannedImageBytes);
    }

    private function makeSshMock(string $body, int $status): FakeSshClient
    {
        $ssh = new FakeSshClient();
        $ssh->queue[] = ['body' => $body, 'status' => $status];

        return $ssh;
    }

    private function chatBody(string $content): string
    {
        return (string) json_encode([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $content]],
            ],
        ]);
    }
}

/**
 * Test-only subclass that swaps the SSH transport for an in-memory stub.
 * Everything else — token resolution, payload encoding, response parsing —
 * runs through the real code.
 */
class TestableChatWithAgentAction extends ChatWithAgentAction
{
    public function __construct(
        Agent $agent,
        string $message,
        array $images,
        private FakeSshClient $sshClient,
        private string $cannedImageBytes = '',
    ) {
        parent::__construct($agent, $message, $images);
    }

    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return $this->sshClient;
    }

    protected function fetchImageBinary(string $url): string
    {
        return $this->cannedImageBytes;
    }
}

/**
 * Minimal stand-in for SshClient. Captures the SFTP payload, captures the first exec
 * command (the main docker cp + curl — not the cleanup `rm` in finally), and dequeues
 * a pre-seeded response on every exec() call.
 */
class FakeSshClient extends SshClient
{
    /** @var list<array{body: string, status: int}> */
    public array $queue = [];
    public int $callCount = 0;
    public string $capturedCommand = '';
    public string $capturedPayload = '';

    public function __construct()
    {
        // intentionally skip parent constructor — we never open a real socket
    }

    public function writeFile(string $remotePath, string $content): bool
    {
        $this->capturedPayload = $content;

        return true;
    }

    public function exec(string $command, int $timeout = 30): string
    {
        // Only the first exec is the "real" one we want to assert on — the second is the
        // cleanup `rm -f` fired from the action's `finally`, which we deliberately ignore.
        if ($this->callCount === 0) {
            $this->capturedCommand = $command;
        }
        $this->callCount++;

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException('FakeSshClient queue is empty');
        }

        return $next['body'] . "\nHTTP_CODE:" . $next['status'];
    }

    public function disconnect(): void
    {
    }
}
