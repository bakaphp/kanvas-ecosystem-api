<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Transports;

use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;
use Throwable;

/**
 * `docker exec curl` over SSH, for a runtime the API cannot route to directly.
 *
 * This is the shape a customer-hosted machine actually has: the container listens on loopback inside
 * the host, nothing is published, and the only way in is the SSH connection Kanvas already holds. It is
 * also the safest shape — the coding runtime is never exposed to a network, so there is no endpoint to
 * find and no port to protect, and the credential never leaves the host.
 *
 * Everything above `HarnessTransport` is unchanged; only the path the bytes take is different.
 */
class SshExecHarnessTransport implements HarnessTransport
{
    private const string STATUS_MARKER = '<<<KANVAS_HTTP_STATUS:';

    public function __construct(
        private readonly AgentMachine $machine,
        private readonly string $container,
        private readonly string $password,
        private readonly string $username = 'opencode',
        private readonly int $port = 4096,
    ) {
    }

    #[Override]
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        int $timeout = 30
    ): array {
        $client = $this->machine->connectSsh();

        try {
            $raw = $client->exec($this->command($method, $path, $body, $timeout), $timeout + 30);
        } catch (Throwable $e) {
            throw new HarnessTransportException(
                'Harness request failed (' . $method . ' ' . $path . ') over ssh: ' . $e->getMessage()
            );
        } finally {
            $client->disconnect();
        }

        return $this->parse($method, $path, $raw);
    }

    /**
     * The body travels base64-encoded and is piped in on stdin.
     *
     * A prompt can carry quotes, newlines and shell metacharacters, and it passes through an SSH command
     * line, a shell and `docker exec` before anything reads it. Encoding removes every one of those
     * layers as a place for it to be mangled — and keeps the request off `ps`, which on a shared host is
     * readable by anyone.
     *
     * @param array<string, mixed>|null $body
     */
    private function command(string $method, string $path, ?array $body, int $timeout): string
    {
        $url = 'http://127.0.0.1:' . $this->port . '/' . ltrim($path, '/');

        $curl = [
            'curl -sS',
            '-w ' . escapeshellarg('\n' . self::STATUS_MARKER . '%{http_code}'),
            '--max-time ' . $timeout,
            '-u ' . escapeshellarg($this->username . ':' . $this->password),
            '-X ' . escapeshellarg($method),
            '-H ' . escapeshellarg('Content-Type: application/json'),
        ];

        if ($body === null) {
            return 'docker exec ' . escapeshellarg($this->container) . ' ' . implode(' ', $curl)
                . ' ' . escapeshellarg($url);
        }

        $curl[] = '--data-binary @-';

        return 'printf %s ' . escapeshellarg(base64_encode((string) json_encode($body)))
            . ' | base64 -d | docker exec -i ' . escapeshellarg($this->container) . ' '
            . implode(' ', $curl) . ' ' . escapeshellarg($url);
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function parse(string $method, string $path, string $raw): array
    {
        $marker = mb_strrpos($raw, self::STATUS_MARKER);

        if ($marker === false) {
            // No marker means curl never ran — a missing container, or docker refusing the exec. The
            // output is the only diagnosis available, so it goes in the message rather than a generic
            // "transport failed".
            throw new HarnessTransportException(
                'Harness request produced no response (' . $method . ' ' . $path . '): '
                . mb_substr(trim($raw), 0, 300)
            );
        }

        $status = (int) trim(mb_substr($raw, $marker + mb_strlen(self::STATUS_MARKER)));
        $payload = rtrim(mb_substr($raw, 0, $marker), "\r\n");

        if ($status >= 400) {
            throw new HarnessTransportException(
                'Harness returned HTTP ' . $status . ' for ' . $method . ' ' . $path . ': '
                . mb_substr($payload, 0, 300)
            );
        }

        return HttpHarnessTransport::decode($payload);
    }
}
