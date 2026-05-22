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

/**
 * Exercises the Hermes ChatWithAgentAction with no SSH / network — the SshClient
 * is injected via a test-only subclass that overrides openSshClient().
 *
 * We're testing:
 *  - command shape (curl hits the Hermes API server, OpenAI chat-completions path)
 *  - happy-path response parsing (choices[0].message.content)
 *  - gateway token resolution (agent custom field, deployment fallback)
 *  - multimodal content when images are attached
 *  - HTTP error surfacing
 *  - malformed JSON surfacing
 *  - missing deployment / missing token guard rails
 */
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
        $this->assertStringContainsString('docker exec', $command);
        $this->assertStringContainsString('hermes-bot', $command);
        $this->assertStringContainsString('curl -sS', $command);
        $this->assertStringContainsString('http://127.0.0.1:8642/v1/chat/completions', $command);
        $this->assertStringContainsString('Authorization: Bearer tok-agent', $command);
        $this->assertStringContainsString('"model":"hermes-agent"', $command);
        $this->assertStringContainsString('"content":"hello"', $command);
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

        $action = $this->makeAction(
            message: 'look',
            agentToken: 'tok',
            deploymentToken: null,
            sshClient: $ssh,
            images: ['https://cdn.example.com/pic.png'],
        );

        $action->execute();

        $command = $ssh->capturedCommand;
        $this->assertStringContainsString('"type":"text"', $command);
        $this->assertStringContainsString('"type":"image_url"', $command);
        $this->assertStringContainsString('https://cdn.example.com/pic.png', $command);
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

        return new TestableChatWithAgentAction($agent, $message, $images, $sshClient);
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
    ) {
        parent::__construct($agent, $message, $images);
    }

    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return $this->sshClient;
    }
}

/**
 * Minimal stand-in for SshClient. Dequeues a pre-seeded response on every
 * exec() call and records the command for later assertions.
 */
class FakeSshClient extends SshClient
{
    /** @var list<array{body: string, status: int}> */
    public array $queue = [];
    public int $callCount = 0;
    public string $capturedCommand = '';

    public function __construct()
    {
        // intentionally skip parent constructor — we never open a real socket
    }

    public function exec(string $command, int $timeout = 30): string
    {
        $this->capturedCommand = $command;
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
