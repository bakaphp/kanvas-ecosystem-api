<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Connectors\AgentRuntime\SshClient as BaseClient;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * OpenClaw SSH client — thin subclass that supplies provider-specific constants.
 *
 * All shared SSH/SFTP logic lives in AgentRuntime\SshClient.
 */
class SshClient extends BaseClient
{
    public static function makeProviderConfig(): ProviderConfig
    {
        return new ProviderConfig(
            providerName: 'openclaw',
            containerPrefix: 'openclaw-',
            queueName: 'openclaw',
            dotDir: 'openclaw',
            configFilename: 'openclaw.json',
            containerHomeDotDir: '/home/node/.openclaw',
            mjsPath: 'node /app/openclaw.mjs',
            cliAlias: 'openclaw',
            defaultSharedImageName: 'openclaw-kanvas:latest',
            defaultSharedImageDir: '/opt/openclaw-image',
            dirPlaceholder: '{{OPENCLAW_DIR}}',
        );
    }

    protected function buildFromCompanyConfig(CompanyInterface $company): void
    {
        $host       = $company->get(ConfigurationEnum::SSH_HOST->value);
        $port       = (int) ($company->get(ConfigurationEnum::SSH_PORT->value) ?? 22);
        $user       = $company->get(ConfigurationEnum::SSH_USER->value);
        $privateKey = $company->get(ConfigurationEnum::SSH_PRIVATE_KEY->value);

        $this->providerHome   = $company->get(ConfigurationEnum::OPENCLAW_HOME->value) ?? '~/.openclaw';
        $this->cliPath        = $company->get(ConfigurationEnum::CLI_PATH->value) ?? 'openclaw';
        $this->configFilename = $company->get(ConfigurationEnum::CONFIG_FILENAME->value) ?? 'openclaw.json';

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
     * BC alias — callers that used getOpenclawHome() continue to work.
     */
    public function getOpenclawHome(): string
    {
        return $this->getProviderHome();
    }
}
