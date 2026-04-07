<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * SSH client for managing OpenClaw agent deployments on remote machines.
 *
 * Two construction modes:
 *  - Company-based: reads SSH creds from company custom fields (legacy CLI model)
 *  - Machine-based: reads SSH creds from AgentMachine model (Docker isolation model)
 *
 * Uses phpseclib3 SFTP (which extends SSH2) for command execution and file transfer.
 */
class SshClient
{
    protected SFTP $sftp;
    protected string $openclawHome;
    protected string $cliPath;
    protected string $configFilename;

    public function __construct(
        protected ?AppInterface $app = null,
        protected ?CompanyInterface $company = null,
    ) {
        if ($this->company !== null) {
            $this->initFromCompany();
        }
    }

    private function initFromCompany(): void
    {
        $host = $this->company->get(ConfigurationEnum::SSH_HOST->value);
        $port = (int) ($this->company->get(ConfigurationEnum::SSH_PORT->value) ?? 22);
        $user = $this->company->get(ConfigurationEnum::SSH_USER->value);
        $privateKey = $this->company->get(ConfigurationEnum::SSH_PRIVATE_KEY->value);

        $this->openclawHome = $this->company->get(ConfigurationEnum::OPENCLAW_HOME->value) ?? '~/.openclaw';
        $this->cliPath = $this->company->get(ConfigurationEnum::CLI_PATH->value) ?? 'openclaw';
        $this->configFilename = $this->company->get(ConfigurationEnum::CONFIG_FILENAME->value) ?? 'openclaw.json';

        if (empty($host) || empty($user) || empty($privateKey)) {
            throw new ValidationException('OpenClaw SSH configuration is missing for this company');
        }

        $this->sftp = new SFTP($host, $port);

        /** @var PrivateKey $key */
        $key = PublicKeyLoader::load($privateKey);

        if (! $this->sftp->login($user, $key)) {
            throw new ValidationException('OpenClaw SSH authentication failed');
        }
    }

    /**
     * Create an SSH client from an AgentMachine model (Docker isolation model).
     * The machine stores its own SSH credentials (host, port, user, private key).
     */
    public static function fromMachine(AgentMachine $machine): self
    {
        $instance = new self();
        // 8-second connect timeout — fail fast if sshd is unreachable/blocked.
        $instance->sftp = new SFTP($machine->host, (int) $machine->ssh_port, 8);

        /** @var PrivateKey $key */
        $key = PublicKeyLoader::load($machine->ssh_private_key);

        if (! $instance->sftp->login($machine->ssh_user, $key)) {
            throw new ValidationException('SSH authentication failed for machine: ' . $machine->name);
        }

        $instance->openclawHome = '~/.openclaw';
        $instance->cliPath = 'openclaw';
        $instance->configFilename = 'openclaw.json';

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
     * Run an OpenClaw CLI subcommand (e.g. "agents list --json").
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
     * then set to the target user. This is used during agent provisioning to
     * write config files into the agent's home directory.
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

    public function readFile(string $remotePath): string
    {
        $result = $this->sftp->get($remotePath);

        return is_string($result) ? $result : '';
    }

    public function getOpenclawHome(): string
    {
        return $this->openclawHome;
    }

    public function getDefaultWorkspacePath(): string
    {
        $configJson = $this->readFile($this->openclawHome . '/' . $this->configFilename);

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

        return $this->openclawHome;
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
     * Returns JSON: { service: { runtime: { status } }, rpc: { ok, url }, gateway: { port } }
     */
    public function getGatewayDeepStatus(): string
    {
        return $this->exec('openclaw gateway status --json --deep 2>/dev/null');
    }

    /**
     * Fetch top-level status: runtimeVersion, linkChannel, heartbeat agents.
     * Returns JSON: { runtimeVersion, linkChannel, heartbeat }
     */
    public function getStatus(): string
    {
        return $this->exec('openclaw status --json 2>/dev/null');
    }

    /**
     * Fetch node service status.
     * Returns JSON: { service: { runtime: { status } } }
     */
    public function getNodeStatus(): string
    {
        return $this->exec('openclaw node status --json 2>/dev/null');
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
     * Returns human-readable table: files, chunks, cache entries, FTS, vector status.
     */
    public function getMemoryStatus(): string
    {
        return $this->exec('openclaw memory status 2>&1');
    }

    /**
     * Fetch config as JSON to get default model and other settings.
     */
    public function getConfig(): string
    {
        return $this->exec('openclaw config show --json 2>/dev/null');
    }

    /**
     * Fetch active sessions as JSON to get context token usage.
     */
    public function getSessions(): string
    {
        return $this->exec('openclaw sessions list --json 2>/dev/null');
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
     * Each section is wrapped by unique sentinel lines so the caller can split them.
     * Returns an associative array keyed by section name (health, status, gateway,
     * node, memory, os), each containing that command's raw stdout.
     *
     * One exec channel = one sshd fork on the server side, regardless of how many
     * commands are piped together.
     *
     * @return array<string, string>
     */
    public function getAllTelemetry(): array
    {
        // Each command is wrapped with `timeout N` so a single hanging command
        // cannot consume the entire exec budget.
        // Individual budgets: health 10s, version 3s, gateway 15s (--deep adds service check), memory 15s.
        // Worst-case total: ~43s. The outer exec timeout (70s) is the final safety net.
        // Job timeout is 120s, giving ample headroom above the 70s outer cap.
        $script = implode('; ', [
            "echo '__SECTION__health'",
            "timeout 10 openclaw health --json 2>&1",
            "echo '__SECTION__version'",
            "timeout 3 openclaw --version 2>/dev/null || echo unknown",
            "echo '__SECTION__gateway'",
            "timeout 15 openclaw gateway status --json --deep 2>/dev/null",
            "echo '__SECTION__memory'",
            "timeout 15 openclaw memory status 2>&1",
        ]);

        // Outer safety net: kill the channel if the whole script exceeds 70 s.
        $raw = $this->exec($script, 70);

        $sections = ['health' => '', 'version' => '', 'gateway' => '', 'memory' => ''];
        $current  = null;

        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, '__SECTION__')) {
                $current = substr($line, strlen('__SECTION__'));
                $current = trim($current);

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
     * Reads the 10 most recently modified session JSONL files for the agent and collects
     * unique tool names from toolCall content blocks in assistant messages. Unlike
     * `openclaw skills list`, which shows what's installed on the host, this reflects
     * what the specific agent has actually used.
     *
     * @param  string  $systemUser  Linux user for the deployment (e.g. "agent-whitco")
     * @param  string  $agentSlug   Openclaw agent slug matching the agents/ sub-dir
     * @return string|null  JSON-encoded array of unique tool names, or null if none found
     */
    public function getAgentTools(string $systemUser, string $agentSlug): ?string
    {
        $sessionsDir = '/home/' . $systemUser . '/.openclaw/agents/' . $agentSlug . '/sessions';

        // Read the 10 most recent session files to get a representative sample.
        // xargs cat feeds all files to a single cat call — one exec channel total.
        $raw = $this->exec(
            'sudo find ' . escapeshellarg($sessionsDir) . ' -name "*.jsonl" -printf "%T@ %p\n" 2>/dev/null'
            . ' | sort -rn | head -10 | awk \'{print $2}\''
            . ' | xargs sudo cat 2>/dev/null',
            15
        );

        if (trim($raw) === '') {
            return null;
        }

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

        $toolList = array_keys($tools);

        return $toolList !== [] ? json_encode(array_values($toolList)) : null;
    }

    /**
     * Fetch the last N activity entries from a deployment's session JSONL files.
     *
     * Each deployment writes conversation events (messages, tool calls, model
     * changes) to JSONL files at:
     *   /home/<systemUser>/.openclaw/agents/<agentSlug>/sessions/*.jsonl
     *
     * We find the most recently modified session files, read the last $limit
     * lines combined, and convert each event into a typed log entry. This
     * requires no RPC auth and survives gateway restarts.
     *
     * Entry types mapped:
     *   session        → info  "Session started"
     *   message/user   → info  "User: <text preview>"
     *   message/asst   → info  "Agent: <text preview>"
     *   message/tool   → info  "Tool result: <toolName>"
     *   model_change   → debug "Model changed to <modelId>"
     *   (other)        → debug "<type>"
     *
     * @param  string  $systemUser  Linux user for the deployment (e.g. "agent-whitco")
     * @param  string  $agentSlug   Openclaw agent slug matching the agents/ sub-dir
     * @param  int     $limit       Max entries to return (default 100)
     * @return array<int, array{ts:string,level:string,msg:string,meta:string|null}>
     */
    public function getDeploymentLogs(string $systemUser, string $agentSlug, int $limit = 100): array
    {
        $sessionsDir = '/home/' . $systemUser . '/.openclaw/agents/' . $agentSlug . '/sessions';

        // Find the 3 most recently modified session files, tail $limit lines total.
        // -printf "%T@ %p\n" emits mtime as a unix timestamp so we can sort numerically.
        // tail -q suppresses the "==> file <==" separators between files.
        $raw = $this->exec(
            'sudo find ' . escapeshellarg($sessionsDir) . ' -name "*.jsonl" -printf "%T@ %p\n" 2>/dev/null'
            . ' | sort -rn | head -3 | awk \'{print $2}\''
            . ' | xargs sudo tail -q -n ' . $limit . ' 2>/dev/null',
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
                        $text = $this->extractTextContent($msg['content'] ?? []);
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
                        $toolName = (string) ($msg['toolName'] ?? 'tool');
                        $isError  = (bool) ($msg['isError'] ?? false);
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
                    // Skip internal housekeeping events (thinking_level_change etc.)
                    break;
            }
        }

        return array_slice($entries, -$limit);
    }

    /**
     * Extract plain text from a content array (mixed text/thinking/toolCall blocks).
     *
     * @param  mixed  $content
     */
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
                // Strip <final>...</final> wrapper openclaw adds
                $text    = (string) $block['text'];
                $text    = preg_replace('/<final>|<\/final>|\[\[reply_to_current\]\]/i', '', $text) ?? $text;
                $parts[] = trim($text);
            }
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Extract tool names from a content array.
     *
     * @param  mixed  $content
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
