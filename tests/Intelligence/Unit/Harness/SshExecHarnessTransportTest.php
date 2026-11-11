<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\Transports\SshExecHarnessTransport;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\Intelligence\Integration\Harness\Fakes\FakeCodingSshClient;
use Tests\TestCase;

/**
 * The same curl over the same SSH connection, inside `docker exec` for a container and bare on the
 * host for a native runtime — and a runtime that is not listening is an error, never `[]`.
 */
class SshExecHarnessTransportTest extends TestCase
{
    private const string MARKER = "\n<<<KANVAS_HTTP_STATUS:";

    public function testAContainerRequestRunsCurlInsideDockerExec(): void
    {
        $ssh = $this->ssh('{}' . self::MARKER . '200');

        $this->transport($ssh, container: 'c')->request('GET', 'api/info');

        $this->assertStringStartsWith("docker exec 'c' curl -sS", $ssh->commands[0]);
        $this->assertStringContainsString('http://127.0.0.1:4096/api/info', $ssh->commands[0]);
    }

    public function testAHostRequestRunsCurlOnTheHost(): void
    {
        $ssh = $this->ssh('{}' . self::MARKER . '200');

        $this->transport($ssh, container: null, port: 4101)->request('GET', 'api/info');

        $this->assertStringStartsWith('curl -sS', $ssh->commands[0]);
        $this->assertStringContainsString(':4101/api/info', $ssh->commands[0]);
        $this->assertStringNotContainsString('docker', $ssh->commands[0]);
    }

    public function testAHostBodyIsPipedThroughBase64(): void
    {
        $ssh = $this->ssh('{}' . self::MARKER . '200');

        $this->transport($ssh, container: null, port: 4101)->request('POST', 'api/session', ['text' => 'hi']);

        $this->assertStringStartsWith("printf %s '", $ssh->commands[0]);
        $this->assertStringContainsString('| base64 -d | curl', $ssh->commands[0]);
        $this->assertStringEndsWith("--data-binary @- 'http://127.0.0.1:4101/api/session'", $ssh->commands[0]);
    }

    public function testAJsonBodyIsDecoded(): void
    {
        $ssh = $this->ssh('{"data":{"id":"s1"}}' . self::MARKER . '200');

        $this->assertSame(['data' => ['id' => 's1']], $this->transport($ssh, container: 'c')->request('GET', 'api/session'));
    }

    /**
     * `curl -w` still prints the marker when the connection is refused, with status 000 and whatever
     * curl said on stderr as the body; read as an empty, successful response, a dead runtime is a
     * session with no messages.
     */
    public function testARefusedConnectionIsATransportError(): void
    {
        $ssh = $this->ssh('curl: (7) Failed to connect' . self::MARKER . '000');

        $this->expectException(HarnessTransportException::class);
        $this->expectExceptionMessage('not listening');

        $this->transport($ssh, container: 'c')->request('GET', 'api/info');
    }

    public function testAnEmptyRefusedConnectionIsATransportError(): void
    {
        $ssh = $this->ssh(self::MARKER . '000');

        $this->expectException(HarnessTransportException::class);
        $this->expectExceptionMessage('not listening');

        $this->transport($ssh, container: null, port: 4101)->request('GET', 'api/info');
    }

    public function testAnHttpErrorCarriesTheStatus(): void
    {
        $ssh = $this->ssh('{"error":"x"}' . self::MARKER . '401');

        $this->expectException(HarnessTransportException::class);
        $this->expectExceptionMessage('HTTP 401');

        $this->transport($ssh, container: 'c')->request('GET', 'api/info');
    }

    public function testNoMarkerSaysWhatDidNotRunInAContainer(): void
    {
        $this->expectException(HarnessTransportException::class);
        $this->expectExceptionMessage('docker');

        $this->transport($this->ssh('Error: No such container: c'), container: 'c')->request('GET', 'api/info');
    }

    public function testNoMarkerSaysWhatDidNotRunOnAHost(): void
    {
        $this->expectException(HarnessTransportException::class);
        $this->expectExceptionMessage('curl did not run');

        $this->transport($this->ssh('bash: curl: command not found'), container: null, port: 4101)->request('GET', 'api/info');
    }

    private function ssh(string $answer): FakeCodingSshClient
    {
        return new FakeCodingSshClient(['' => $answer]);
    }

    private function transport(FakeCodingSshClient $ssh, ?string $container, int $port = 4096): SshExecHarnessTransport
    {
        $machine = new AgentMachine();
        $machine->name = 'box';

        return new class (
            $machine,
            $container,
            $port,
            $ssh
        ) extends SshExecHarnessTransport {
            public function __construct(
                AgentMachine $machine,
                ?string $container,
                int $port,
                private readonly FakeCodingSshClient $ssh
            ) {
                parent::__construct(
                    machine: $machine,
                    container: $container,
                    password: 'pw',
                    port: $port
                );
            }

            protected function openSshClient(): SshClient
            {
                return $this->ssh;
            }
        };
    }
}
