<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\OpenClaw;

use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentAction;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ChatWithAgentAction with no SSH / network — the SshClient is
 * injected via a test-only subclass that overrides openSshClient(), and
 * token-fetching is overridden via fetchGatewayToken().
 *
 * We're testing:
 *  - command shape (curl invocation carries the right headers + payload)
 *  - happy-path response parsing
 *  - cached-token reuse (no refetch when the deployment already has it)
 *  - lazy fetch when the deployment has no cached token
 *  - 401 refresh + retry once
 *  - HTTP error surfacing
 *  - malformed JSON surfacing
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
        $body = json_encode([
            'output' => [
                ['content' => [['type' => 'output_text', 'text' => 'hello back']]],
            ],
        ]);

        $ssh = $this->makeSshMock($body, 200, expectCalls: 1);

        $action = $this->makeAction(
            agentSlug: 'bot',
            message: 'hello',
            sessionKey: null,
            cachedToken: 'tok-cached',
            sshClient: $ssh,
        );

        $result = $action->execute();

        $this->assertSame('hello back', $result);

        // Verify the curl command shape
        $command = $ssh->capturedCommand;
        $this->assertStringContainsString('docker exec', $command);
        $this->assertStringContainsString('curl -sS', $command);
        $this->assertStringContainsString('http://127.0.0.1:18789/v1/responses', $command);
        $this->assertStringContainsString('Authorization: Bearer tok-cached', $command);
        $this->assertStringContainsString('x-openclaw-agent-id: bot', $command);
        $this->assertStringContainsString('x-openclaw-session-key: agent:bot:main', $command);
    }

    public function testCustomSessionKeyIsNamespacedUnderAgentSlug(): void
    {
        $body = json_encode([
            'output' => [['content' => [['type' => 'output_text', 'text' => 'ok']]]],
        ]);

        $ssh = $this->makeSshMock($body, 200, expectCalls: 1);

        $action = $this->makeAction(
            agentSlug: 'ops',
            message: 'ping',
            sessionKey: 'kanvas-channel-123',
            cachedToken: 'tok-x',
            sshClient: $ssh,
        );

        $action->execute();

        $this->assertStringContainsString(
            'x-openclaw-session-key: agent:ops:kanvas-channel-123',
            $ssh->capturedCommand
        );
    }

    public function testUsesCachedTokenWithoutTriggeringLazyFetch(): void
    {
        $body = json_encode([
            'output' => [['content' => [['type' => 'output_text', 'text' => 'ok']]]],
        ]);
        $ssh = $this->makeSshMock($body, 200, expectCalls: 1);

        $action = $this->makeAction(
            agentSlug: 'bot',
            message: 'hi',
            sessionKey: null,
            cachedToken: 'tok-already-there',
            sshClient: $ssh,
        );

        $action->execute();

        $this->assertSame(0, $action->fetchCalls, 'Should not lazy-fetch when token is already cached');
    }

    public function testLazyFetchesAndCachesTokenWhenDeploymentHasNone(): void
    {
        $body = json_encode([
            'output' => [['content' => [['type' => 'output_text', 'text' => 'ok']]]],
        ]);
        $ssh = $this->makeSshMock($body, 200, expectCalls: 1);

        $action = $this->makeAction(
            agentSlug: 'bot',
            message: 'hi',
            sessionKey: null,
            cachedToken: null,          // no cached token
            sshClient: $ssh,
            fakeFetchedToken: 'tok-fetched-from-disk',
        );

        $action->execute();

        $this->assertSame(1, $action->fetchCalls, 'Should lazy-fetch exactly once');
        $this->assertSame('tok-fetched-from-disk', $action->deploymentStub->lastSetToken);
        $this->assertStringContainsString(
            'Authorization: Bearer tok-fetched-from-disk',
            $ssh->capturedCommand,
        );
    }

    public function testOn401RefreshesTokenAndRetriesOnce(): void
    {
        $success = json_encode([
            'output' => [['content' => [['type' => 'output_text', 'text' => 'second try' ]]]],
        ]);

        // First call returns 401, second succeeds
        $ssh = new FakeSshClient();
        $ssh->queue = [
            ['body' => '{"error":"Unauthorized"}', 'status' => 401],
            ['body' => $success, 'status' => 200],
        ];

        $action = $this->makeAction(
            agentSlug: 'bot',
            message: 'hi',
            sessionKey: null,
            cachedToken: 'tok-stale',
            sshClient: $ssh,
            fakeFetchedToken: 'tok-refreshed',
        );

        $result = $action->execute();

        $this->assertSame('second try', $result);
        $this->assertSame(2, $ssh->callCount, 'Should call SSH twice: initial + retry');
        $this->assertSame(1, $action->fetchCalls, 'Should refresh token exactly once');
        $this->assertStringContainsString(
            'Authorization: Bearer tok-refreshed',
            $ssh->lastCommand(),
            'Retry must use the refreshed token',
        );
    }

    public function testThrowsOnNon2xxHttpError(): void
    {
        $ssh = $this->makeSshMock('{"error":"boom"}', 500, expectCalls: 1);

        $action = $this->makeAction(
            agentSlug: 'bot',
            message: 'hi',
            sessionKey: null,
            cachedToken: 'tok',
            sshClient: $ssh,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('HTTP 500');

        $action->execute();
    }

    public function testThrowsWhenResponseBodyIsMalformedJson(): void
    {
        $ssh = $this->makeSshMock('<html>gateway offline</html>', 200, expectCalls: 1);

        $action = $this->makeAction(
            agentSlug: 'bot',
            message: 'hi',
            sessionKey: null,
            cachedToken: 'tok',
            sshClient: $ssh,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('non-JSON response');

        $action->execute();
    }

    public function testThrowsWhenAgentHasNoActiveDeployment(): void
    {
        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('getAttribute')->with('activeDeployment')->andReturn(null);

        $action = new ChatWithAgentAction($agent, 'hi', null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('active Docker deployment');

        $action->execute();
    }

    private function makeAction(
        string $agentSlug,
        string $message,
        ?string $sessionKey,
        ?string $cachedToken,
        FakeSshClient $sshClient,
        ?string $fakeFetchedToken = null,
    ): TestableChatWithAgentAction {
        $machine = Mockery::mock(AgentMachine::class);

        $stub = new DeploymentStub(cachedToken: $cachedToken);

        $deployment = Mockery::mock(AgentDeployment::class)->makePartial();
        $deployment->shouldReceive('isRunning')->andReturn(true);
        $deployment->shouldReceive('get')
            ->with('OPENCLAW_GATEWAY_TOKEN')
            ->andReturnUsing(fn () => $stub->cachedToken);
        $customField = Mockery::mock(AppsCustomFields::class);
        $deployment->shouldReceive('set')
            ->with('OPENCLAW_GATEWAY_TOKEN', Mockery::type('string'))
            ->andReturnUsing(function (string $key, string $value) use ($stub, $customField) {
                $stub->cachedToken = $value;
                $stub->lastSetToken = $value;

                return $customField;
            });
        $deployment->shouldReceive('getAttribute')
            ->with('machine')
            ->andReturn($machine);
        $deployment->shouldReceive('getAttribute')
            ->with('container_name')
            ->andReturn('agent-' . $agentSlug);

        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('getAttribute')
            ->with('activeDeployment')
            ->andReturn($deployment);
        $agent->shouldReceive('getAttribute')
            ->with('slug')
            ->andReturn($agentSlug);

        return new TestableChatWithAgentAction(
            $agent,
            $message,
            $sessionKey,
            $sshClient,
            $fakeFetchedToken ?? 'unused-token',
            $stub,
        );
    }

    private function makeSshMock(string $body, int $status, int $expectCalls): FakeSshClient
    {
        $ssh = new FakeSshClient();
        for ($i = 0; $i < $expectCalls; $i++) {
            $ssh->queue[] = ['body' => $body, 'status' => $status];
        }

        return $ssh;
    }
}

/**
 * Test-only subclass that swaps the SSH transport + token fetcher for
 * in-memory stubs. Everything else — session-key building, JSON parsing,
 * 401 retry logic — runs through the real code.
 */
class TestableChatWithAgentAction extends ChatWithAgentAction
{
    public int $fetchCalls = 0;

    public function __construct(
        Agent $agent,
        string $message,
        ?string $sessionKey,
        private FakeSshClient $sshClient,
        private string $fetchedToken,
        public DeploymentStub $deploymentStub,
    ) {
        parent::__construct($agent, $message, $sessionKey);
    }

    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return $this->sshClient;
    }

    protected function fetchGatewayToken(AgentDeployment $deployment): string
    {
        $this->fetchCalls++;

        return $this->fetchedToken;
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
    /** @var list<string> */
    public array $allCommands = [];

    public function __construct()
    {
        // intentionally skip parent constructor — we never open a real socket
    }

    public function exec(string $command, int $timeout = 30): string
    {
        $this->capturedCommand = $command;
        $this->allCommands[] = $command;
        $this->callCount++;

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \RuntimeException('FakeSshClient queue is empty');
        }

        return $next['body'] . "\nHTTP_CODE:" . $next['status'];
    }

    public function disconnect(): void
    {
    }

    public function lastCommand(): string
    {
        $count = count($this->allCommands);

        return $count > 0 ? $this->allCommands[$count - 1] : '';
    }
}

/**
 * Plain state holder — Mockery handles the AgentDeployment methods, this
 * just keeps shared state between the get/set expectations and the test
 * assertions (so we can see which token was last cached).
 */
class DeploymentStub
{
    public ?string $lastSetToken = null;

    public function __construct(public ?string $cachedToken)
    {
    }
}
