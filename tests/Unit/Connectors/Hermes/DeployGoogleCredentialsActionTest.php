<?php

declare(strict_types=1);

namespace Tests\Unit\Connectors\Hermes;

use Kanvas\Connectors\Hermes\Actions\DeployGoogleCredentialsAction;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentIntegrationConfigKeyEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Mockery;
use PHPUnit\Framework\TestCase;

class DeployGoogleCredentialsActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testWritesBothCredentialFilesAndRestartsContainer(): void
    {
        $ssh = new FakeDeployGoogleSshClient();
        $agent = $this->makeAgentWithDeployment(
            config: [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'refresh_token' => 'rtok',
                'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
            ],
            isRunning: true,
        );

        $action = new TestableDeployGoogleCredentialsAction($agent, $ssh);

        $result = $action->execute();

        $this->assertTrue($result['deployed']);
        $this->assertCount(2, $result['files']);
        $this->assertSame(
            '/home/agent-7/.hermes/google_token.json',
            $result['files'][0]
        );
        $this->assertSame(
            '/home/agent-7/.hermes/google_client_secret.json',
            $result['files'][1]
        );

        // Two files written, owner is the system_user passed to writeFileAsUser
        $this->assertCount(2, $ssh->writtenFiles);
        $this->assertSame('agent-7-user', $ssh->writtenFiles[0]['systemUser']);
        $this->assertSame('agent-7-user', $ssh->writtenFiles[1]['systemUser']);

        // Each write is followed by a UID-1000 chown so the in-container node user
        // can read the credential file regardless of the host user that owns the dir.
        $chownCommands = array_values(array_filter(
            $ssh->execCommands,
            fn (string $cmd) => str_starts_with($cmd, 'sudo chown 1000:1000'),
        ));
        $this->assertCount(2, $chownCommands);

        // Last exec call must restart the container so the runtime picks up the files.
        $restartCommand = end($ssh->execCommands);
        $this->assertStringContainsString('docker compose restart', $restartCommand);
        $this->assertStringContainsString("sudo -u 'agent-7-user'", $restartCommand);
        $this->assertStringContainsString('/home/agent-7/.hermes', $restartCommand);

        $this->assertTrue($ssh->disconnected);
    }

    public function testReturnsEarlyWhenNoActiveDeployment(): void
    {
        $ssh = new FakeDeployGoogleSshClient();
        $agent = $this->makeAgentWithDeployment(
            config: [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'refresh_token' => 'rtok',
            ],
            isRunning: false,
        );

        $action = new TestableDeployGoogleCredentialsAction($agent, $ssh);
        $result = $action->execute();

        $this->assertFalse($result['deployed']);
        $this->assertSame('no_running_deployment', $result['reason']);
        $this->assertSame([], $ssh->writtenFiles);
        $this->assertSame([], $ssh->execCommands);
    }

    public function testThrowsWhenAgentHasNoGoogleConfig(): void
    {
        $ssh = new FakeDeployGoogleSshClient();
        $agent = $this->makeAgentWithDeployment(config: null, isRunning: true);

        $action = new TestableDeployGoogleCredentialsAction($agent, $ssh);

        $this->expectException(ValidationException::class);
        $action->execute();
    }

    public function testFinallyDisconnectsEvenWhenWriteThrows(): void
    {
        $ssh = new FakeDeployGoogleSshClient();
        $ssh->throwOnWrite = true;

        $agent = $this->makeAgentWithDeployment(
            config: [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'refresh_token' => 'rtok',
            ],
            isRunning: true,
        );

        $action = new TestableDeployGoogleCredentialsAction($agent, $ssh);

        try {
            $action->execute();
            $this->fail('Expected write exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertTrue($ssh->disconnected);
    }

    private function makeAgentWithDeployment(mixed $config, bool $isRunning): Agent
    {
        $machine = Mockery::mock(AgentMachine::class);

        $deployment = Mockery::mock(AgentDeployment::class)->makePartial();
        $deployment->shouldReceive('isRunning')->andReturn($isRunning);
        $deployment->shouldReceive('getAttribute')->with('home_directory')->andReturn('/home/agent-7');
        $deployment->shouldReceive('getAttribute')->with('system_user')->andReturn('agent-7-user');
        $deployment->shouldReceive('getAttribute')->with('machine')->andReturn($machine);
        $deployment->shouldReceive('getId')->andReturn(42);

        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('get')
            ->with(AgentIntegrationConfigKeyEnum::GOOGLE->value)
            ->andReturn($config);
        $agent->shouldReceive('getAttribute')->with('activeDeployment')->andReturn($deployment);
        $agent->shouldReceive('getId')->andReturn(7);

        /** @var Agent $agent */
        return $agent;
    }
}

class TestableDeployGoogleCredentialsAction extends DeployGoogleCredentialsAction
{
    public function __construct(
        Agent $agent,
        private SshClient $sshClient,
    ) {
        parent::__construct($agent);
    }

    protected function openSshClient(AgentMachine $machine): SshClient
    {
        return $this->sshClient;
    }
}

class FakeDeployGoogleSshClient extends SshClient
{
    /** @var list<array{path: string, content: string, systemUser: string}> */
    public array $writtenFiles = [];
    /** @var list<string> */
    public array $execCommands = [];
    public bool $disconnected = false;
    public bool $throwOnWrite = false;

    public function __construct()
    {
        // skip parent constructor — never open a real SFTP socket
    }

    public function writeFileAsUser(string $remotePath, string $content, string $systemUser): void
    {
        if ($this->throwOnWrite) {
            throw new \RuntimeException('boom');
        }
        $this->writtenFiles[] = [
            'path' => $remotePath,
            'content' => $content,
            'systemUser' => $systemUser,
        ];
    }

    public function exec(string $command, int $timeout = 30): string
    {
        $this->execCommands[] = $command;

        return '';
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }
}
