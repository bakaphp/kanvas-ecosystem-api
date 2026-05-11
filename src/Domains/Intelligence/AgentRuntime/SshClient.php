<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * Abstract SSH client for managing agent deployments on remote machines.
 *
 * Two construction modes:
 *  - Company-based: reads SSH creds from company custom fields (legacy CLI model)
 *  - Machine-based: reads SSH creds from AgentMachine model (Docker isolation model)
 *
 * Uses phpseclib3 SFTP (which extends SSH2) for command execution and file transfer.
 *
 * Concrete subclasses (OpenClaw\SshClient, Hermes\SshClient) implement:
 *  - makeProviderConfig(): ProviderConfig — supplies all provider-specific constants
 *  - buildFromCompanyConfig(CompanyInterface): void — reads provider-specific ConfigurationEnum keys
 */
abstract class SshClient
{
    protected SFTP $sftp;
    protected ProviderConfig $providerConfig;
    protected string $providerHome;
    protected string $cliPath;
    protected string $configFilename;

    public function __construct(
        protected ?AppInterface $app = null,
        protected ?CompanyInterface $company = null,
    ) {
        $this->providerConfig = static::makeProviderConfig();

        if ($this->company !== null) {
            $this->buildFromCompanyConfig($this->company);
        }
    }

    /**
     * Returns the provider-specific configuration constants.
     * Implemented by each concrete SshClient subclass.
     */
    abstract public static function makeProviderConfig(): ProviderConfig;

    /**
     * Reads SSH credentials and provider paths from company custom fields.
     * Uses the concrete provider's ConfigurationEnum keys.
     * Must set $this->sftp, $this->providerHome, $this->cliPath, $this->configFilename.
     */
    abstract protected function buildFromCompanyConfig(CompanyInterface $company): void;

    /**
     * Create an SSH client from an AgentMachine model (Docker isolation model).
     * The machine stores its own SSH credentials (host, port, user, private key).
     */
    public static function fromMachine(AgentMachine $machine): static
    {
        $instance = new static();
        $instance->providerConfig = static::makeProviderConfig();

        // 15-second connect timeout — fail fast if sshd is unreachable/blocked.
        $instance->sftp = new SFTP($machine->host, (int) $machine->ssh_port, 15);
        $instance->sftp->setTimeout(60);

        /** @var PrivateKey $key */
        $key = PublicKeyLoader::load($machine->ssh_private_key);

        if (! $instance->sftp->login($machine->ssh_user, $key)) {
            throw new ValidationException('SSH authentication failed for machine: ' . $machine->name);
        }

        $config = $instance->providerConfig;
        $instance->providerHome     = '~/.' . $config->dotDir;
        $instance->cliPath          = $config->cliAlias;
        $instance->configFilename   = $config->configFilename;

        return $instance;
    }

    /**
     * Execute a shell command on the remote machine.
     *
     * @param int $timeout Seconds before the command times out. Use 120+ for
     *                     long-running operations like LLM API calls via docker exec.
     */
    public function exec(string $command, int $timeout = 30): string
    {
        $this->sftp->setTimeout($timeout);
        $result = $this->sftp->exec($command);

        return is_string($result) ? $result : '';
    }

    /**
     * Run a provider CLI subcommand (e.g. "agents list --json").
     */
    public function cli(string $subcommand): string
    {
        return $this->exec($this->cliPath . ' ' . $subcommand);
    }

    /**
     * Write a file on the remote machine as a specific Linux user via sudo.
     *
     * Content is base64-encoded locally, decoded on the remote side via pipe,
     * and written with `sudo tee` to avoid permission issues. Ownership is
     * then set to the target user.
     */
    public function writeFileAsUser(string $remotePath, string $content, string $systemUser): void
    {
        $encoded = base64_encode($content);
        $this->exec(
            'echo ' . escapeshellarg($encoded)
            . ' | base64 -d | sudo tee ' . escapeshellarg($remotePath) . ' > /dev/null'
            . ' && sudo chown ' . escapeshellarg($systemUser . ':' . $systemUser) . ' ' . escapeshellarg($remotePath)
        );
    }

    /**
     * Write a file via SFTP (direct transfer, no sudo).
     */
    public function writeFile(string $remotePath, string $content): bool
    {
        $dir = dirname($remotePath);
        $this->sftp->mkdir($dir, 0755, true);

        return $this->sftp->put($remotePath, $content);
    }

    /**
     * Upload a local file to the remote machine via SFTP without loading it into memory.
     */
    public function uploadFromFile(string $remotePath, string $localPath): bool
    {
        $dir = dirname($remotePath);
        $this->sftp->mkdir($dir, 0755, true);

        return $this->sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE);
    }

    public function readFile(string $remotePath): string
    {
        $result = $this->sftp->get($remotePath);

        return is_string($result) ? $result : '';
    }

    /**
     * Download a remote file to a local path via SFTP without loading it into memory.
     */
    public function downloadToFile(string $remotePath, string $localPath): bool
    {
        return (bool) $this->sftp->get($remotePath, $localPath);
    }

    public function getProviderHome(): string
    {
        return $this->providerHome;
    }

    public function getDefaultWorkspacePath(): string
    {
        $configJson = $this->readFile($this->providerHome . '/' . $this->configFilename);

        if ($configJson !== '') {
            /** @var array<string, mixed> $config */
            $config = json_decode($configJson, true);
            /** @var array<string, mixed> $agents */
            $agents = $config['agents'] ?? [];
            /** @var array<string, mixed> $defaults */
            $defaults = $agents['defaults'] ?? [];
            $workspace = (string) ($defaults['workspace'] ?? '');

            if ($workspace !== '') {
                return $workspace;
            }
        }

        return $this->providerHome;
    }

    public function getWorkspacePath(string $agentId): string
    {
        return $this->getDefaultWorkspacePath() . '-' . $agentId;
    }

    public function getGatewayStatus(): string
    {
        return $this->cli('gateway status 2>&1');
    }

    /**
     * Fetch structured runtime + RPC status for the gateway service.
     */
    public function getGatewayDeepStatus(): string
    {
        return $this->exec($this->providerConfig->cliAlias . ' gateway status --json --deep 2>/dev/null');
    }

    /**
     * Fetch top-level status: runtimeVersion, linkChannel, heartbeat agents.
     */
    public function getStatus(): string
    {
        return $this->exec($this->providerConfig->cliAlias . ' status --json 2>/dev/null');
    }

    /**
     * Fetch node service status.
     */
    public function getNodeStatus(): string
    {
        return $this->exec($this->providerConfig->cliAlias . ' node status --json 2>/dev/null');
    }

    public function restartGateway(): string
    {
        return $this->cli('gateway restart 2>&1');
    }

    public function getGatewayLogs(int $lines = 100): string
    {
        return $this->cli('gateway logs --lines ' . $lines . ' 2>&1');
    }

    public function listAgents(): string
    {
        return $this->cli('agents list --json 2>&1');
    }

    public function getUsage(): string
    {
        return $this->cli('status --usage 2>&1');
    }

    public function getHealth(): string
    {
        return $this->cli('health --json 2>&1');
    }

    /**
     * Fetch memory subsystem status (text output — no --json flag available).
     */
    public function getMemoryStatus(): string
    {
        return $this->exec($this->providerConfig->cliAlias . ' memory status 2>&1');
    }

    /**
     * Fetch config as JSON to get default model and other settings.
     */
    public function getConfig(): string
    {
        return $this->exec($this->providerConfig->cliAlias . ' config show --json 2>/dev/null');
    }

    /**
     * Fetch active sessions as JSON to get context token usage.
     */
    public function getSessions(): string
    {
        return $this->exec($this->providerConfig->cliAlias . ' sessions list --json 2>/dev/null');
    }

    /**
     * Get OS info from the remote machine.
     */
    public function getOsInfo(): string
    {
        return $this->exec('uname -s -r 2>/dev/null || echo unknown');
    }

    /**
     * Run all telemetry commands in a SINGLE exec channel to minimise SSH daemon load.
     *
     * Commands run via `docker exec` against the agent's container because the runtime
     * CLI lives inside the container, not on the host filesystem.
     *
     * @return array<string, string>
     */
    public function getAllTelemetryForContainer(string $containerName): array
    {
        $c   = escapeshellarg($containerName);
        $cli = $this->providerConfig->mjsPath;

        $script = implode('; ', [
            "echo '__SECTION__health'",
            "docker exec {$c} timeout 10 {$cli} health --json 2>&1",
            "echo '__SECTION__version'",
            "docker exec {$c} timeout 3 {$cli} --version 2>/dev/null || echo unknown",
            "echo '__SECTION__gateway'",
            "docker exec {$c} timeout 15 {$cli} gateway status --json --deep 2>/dev/null",
            "echo '__SECTION__memory'",
            "docker exec {$c} timeout 15 {$cli} memory status 2>&1",
        ]);

        $raw = $this->exec($script, 70);

        $sections = ['health' => '', 'version' => '', 'gateway' => '', 'memory' => ''];
        $current  = null;

        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, '__SECTION__')) {
                $current = trim(substr($line, strlen('__SECTION__')));

                continue;
            }

            if ($current !== null && array_key_exists($current, $sections)) {
                $sections[$current] .= $line . "\n";
            }
        }

        return $sections;
    }

    /**
     * @deprecated Use getAllTelemetryForContainer() for Docker-deployed agents.
     * @return array<string, string>
     */
    public function getAllTelemetry(): array
    {
        $alias  = $this->providerConfig->cliAlias;
        $script = implode('; ', [
            "echo '__SECTION__health'",
            "timeout 10 {$alias} health --json 2>&1",
            "echo '__SECTION__version'",
            "timeout 3 {$alias} --version 2>/dev/null || echo unknown",
            "echo '__SECTION__gateway'",
            "timeout 15 {$alias} gateway status --json --deep 2>/dev/null",
            "echo '__SECTION__memory'",
            "timeout 15 {$alias} memory status 2>&1",
        ]);

        $raw      = $this->exec($script, 70);
        $sections = ['health' => '', 'version' => '', 'gateway' => '', 'memory' => ''];
        $current  = null;

        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, '__SECTION__')) {
                $current = trim(substr($line, strlen('__SECTION__')));

                continue;
            }

            if ($current !== null && array_key_exists($current, $sections)) {
                $sections[$current] .= $line . "\n";
            }
        }

        return $sections;
    }

    /**
     * Extract the unique set of tools this agent has actually called across its recent sessions.
     *
     * @return string|null JSON-encoded array of unique tool names, or null if none found
     */
    public function getAgentTools(string $containerName, string $agentSlug): ?string
    {
        $sessionsDir = $this->providerConfig->containerHomeDotDir . '/agents/' . $agentSlug . '/sessions';

        $inner = 'find ' . escapeshellarg($sessionsDir) . ' -name "*.jsonl" 2>/dev/null | xargs -r cat 2>/dev/null';
        $raw   = $this->exec(
            'docker exec ' . escapeshellarg($containerName) . ' bash -c ' . escapeshellarg($inner),
            15
        );

        if (trim($raw) !== '') {
            $tools = [];

            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $event = json_decode($line, true);

                if (! is_array($event) || ($event['type'] ?? '') !== 'message') {
                    continue;
                }

                /** @var array<string, mixed> $msg */
                $msg = $event['message'] ?? [];

                if (($msg['role'] ?? '') !== 'assistant') {
                    continue;
                }

                foreach ($this->extractToolNames($msg['content'] ?? []) as $name) {
                    $tools[$name] = true;
                }
            }

            if ($tools !== []) {
                return json_encode(array_values(array_keys($tools)));
            }
        }

        $mjsPath   = $this->providerConfig->mjsPath;
        $skillsRaw = $this->exec(
            'docker exec ' . escapeshellarg($containerName) . ' timeout 5 ' . $mjsPath . ' skills list --json 2>/dev/null',
            10
        );

        $start = strpos($skillsRaw, '{');
        $end   = strrpos($skillsRaw, '}');

        if ($start !== false && $end !== false && $end > $start) {
            /** @var array<string, mixed>|null $data */
            $data = json_decode(substr($skillsRaw, $start, $end - $start + 1), true);

            if (is_array($data)) {
                /** @var array<int, array<string, mixed>> $list */
                $list = $data['skills'] ?? [];

                $eligible = array_values(array_map(
                    fn (array $s) => (string) $s['name'],
                    array_filter($list, fn (array $s) => (bool) ($s['eligible'] ?? false))
                ));

                if ($eligible !== []) {
                    return json_encode($eligible);
                }
            }
        }

        return null;
    }

    /**
     * Fetch the last N activity entries from a deployment's session JSONL files.
     *
     * @return array<int, array{ts:string,level:string,msg:string,meta:string|null}>
     */
    public function getDeploymentLogs(string $containerName, string $agentSlug, int $limit = 100): array
    {
        $sessionsDir = $this->providerConfig->containerHomeDotDir . '/agents/' . $agentSlug . '/sessions';

        $inner = 'find ' . escapeshellarg($sessionsDir) . ' -name "*.jsonl" -printf "%T@ %p\n" 2>/dev/null'
            . ' | sort -rn | head -3 | awk \'{print $2}\''
            . ' | xargs tail -q -n ' . $limit . ' 2>/dev/null';

        $raw = $this->exec(
            'docker exec ' . escapeshellarg($containerName) . ' bash -c ' . escapeshellarg($inner),
            10
        );

        if (trim($raw) === '') {
            return [];
        }

        $entries = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);

            if (! is_array($event)) {
                continue;
            }

            $ts   = (string) ($event['timestamp'] ?? '');
            $type = (string) ($event['type'] ?? '');

            switch ($type) {
                case 'session':
                    $entries[] = [
                        'ts'    => $ts,
                        'level' => 'info',
                        'msg'   => 'Session started',
                        'meta'  => isset($event['id']) ? json_encode(['session' => $event['id'], 'cwd' => $event['cwd'] ?? null]) : null,
                    ];
                    break;

                case 'message':
                    /** @var array<string, mixed> $msg */
                    $msg  = $event['message'] ?? [];
                    $role = (string) ($msg['role'] ?? '');

                    if ($role === 'user') {
                        $text      = $this->extractTextContent($msg['content'] ?? []);
                        $entries[] = [
                            'ts'    => $ts,
                            'level' => 'info',
                            'msg'   => 'User: ' . $this->truncate($text, 120),
                            'meta'  => null,
                        ];
                    } elseif ($role === 'assistant') {
                        $text      = $this->extractTextContent($msg['content'] ?? []);
                        $toolNames = $this->extractToolNames($msg['content'] ?? []);
                        $usage     = $msg['usage'] ?? null;
                        $entries[] = [
                            'ts'    => $ts,
                            'level' => 'info',
                            'msg'   => $toolNames
                                ? 'Agent called: ' . implode(', ', $toolNames)
                                : 'Agent: ' . $this->truncate($text, 120),
                            'meta'  => $usage !== null ? json_encode([
                                'model'  => $msg['model'] ?? null,
                                'tokens' => ($usage['totalTokens'] ?? null),
                                'cost'   => isset($usage['cost']['total']) ? round((float) $usage['cost']['total'], 5) : null,
                            ]) : null,
                        ];
                    } elseif ($role === 'toolResult') {
                        $toolName  = (string) ($msg['toolName'] ?? 'tool');
                        $isError   = (bool) ($msg['isError'] ?? false);
                        $entries[] = [
                            'ts'    => $ts,
                            'level' => $isError ? 'error' : 'debug',
                            'msg'   => 'Tool result: ' . $toolName . ($isError ? ' (error)' : ''),
                            'meta'  => null,
                        ];
                    }
                    break;

                case 'model_change':
                    $entries[] = [
                        'ts'    => $ts,
                        'level' => 'debug',
                        'msg'   => 'Model changed to ' . ($event['modelId'] ?? 'unknown'),
                        'meta'  => null,
                    ];
                    break;

                default:
                    break;
            }
        }

        return array_slice($entries, -$limit);
    }

    private function extractTextContent(mixed $content): string
    {
        if (! is_array($content)) {
            return is_string($content) ? $content : '';
        }

        $parts = [];

        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $text    = (string) $block['text'];
                $text    = preg_replace('/<final>|<\/final>|\[\[reply_to_current\]\]/i', '', $text) ?? $text;
                $parts[] = trim($text);
            }
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * @return string[]
     */
    private function extractToolNames(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $names = [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'toolCall' && isset($block['name'])) {
                $names[] = (string) $block['name'];
            }
        }

        return $names;
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    public function disconnect(): void
    {
        $this->sftp->disconnect();
    }
}
