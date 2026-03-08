<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

class SshClient
{
    protected SFTP $sftp;
    protected string $openclawHome;

    public function __construct(
        protected CompanyInterface $company
    ) {
        $host = $this->company->get(ConfigurationEnum::SSH_HOST->value);
        $port = (int) ($this->company->get(ConfigurationEnum::SSH_PORT->value) ?? 22);
        $user = $this->company->get(ConfigurationEnum::SSH_USER->value);
        $privateKey = $this->company->get(ConfigurationEnum::SSH_PRIVATE_KEY->value);
        $this->openclawHome = $this->company->get(ConfigurationEnum::OPENCLAW_HOME->value) ?? '~/.openclaw';

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

    public function exec(string $command): string
    {
        $result = $this->sftp->exec($command);

        return is_string($result) ? $result : '';
    }

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

    public function getWorkspacePath(string $agentId): string
    {
        return $this->openclawHome . '/workspace-' . $agentId;
    }

    public function getGatewayStatus(): string
    {
        return $this->exec('openclaw gateway status 2>&1');
    }

    public function disconnect(): void
    {
        $this->sftp->disconnect();
    }
}
