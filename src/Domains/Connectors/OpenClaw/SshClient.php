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
        $instance->sftp = new SFTP($machine->host, (int) $machine->ssh_port);

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

    public function disconnect(): void
    {
        $this->sftp->disconnect();
    }
}
