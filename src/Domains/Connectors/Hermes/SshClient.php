<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Hermes\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseClient;
use Override;
use phpseclib4\Crypt\Common\PrivateKey;
use phpseclib4\Crypt\PublicKeyLoader;
use phpseclib4\Net\SFTP;

/**
 * Hermes SSH client — thin subclass that supplies provider-specific constants.
 *
 * All shared SSH/SFTP logic lives in AgentRuntime\SshClient.
 */
class SshClient extends BaseClient
{
    #[Override]
    public static function makeProviderConfig(): ProviderConfig
    {
        return new ProviderConfig(
            providerName: 'hermes',
            containerPrefix: 'hermes-',
            queueName: 'agent-runtime',
            dotDir: 'hermes',
            configFilename: 'config.yaml',
            containerHomeDotDir: '/home/node/.hermes',
            mjsPath: 'node /app/hermes.mjs',
            cliAlias: 'hermes',
            defaultSharedImageName: 'hermes-kanvas:latest',
            defaultSharedImageDir: '/opt/hermes-image',
            dirPlaceholder: '{{HERMES_DIR}}',
            gatewayTokenCustomFieldKey: 'HERMES_GATEWAY_TOKEN',
            deploymentIdCustomFieldKey: 'HERMES_DEPLOYMENT_ID',
            gatewayTokenConfigKey: ConfigurationEnum::GATEWAY_TOKEN->value,
        );
    }

    #[Override]
    protected function buildFromCompanyConfig(CompanyInterface $company): void
    {
        $host = $company->get(ConfigurationEnum::SSH_HOST->value);
        $port = (int) ($company->get(ConfigurationEnum::SSH_PORT->value) ?? 22);
        $user = $company->get(ConfigurationEnum::SSH_USER->value);
        $privateKey = $company->get(ConfigurationEnum::SSH_PRIVATE_KEY->value);

        $this->providerHome = $company->get(ConfigurationEnum::HERMES_HOME->value) ?? '~/.hermes';
        $this->cliPath = $company->get(ConfigurationEnum::CLI_PATH->value) ?? 'hermes';
        $this->configFilename = $company->get(ConfigurationEnum::CONFIG_FILENAME->value) ?? 'hermes.json';

        if (empty($host) || empty($user) || empty($privateKey)) {
            throw new ValidationException('Hermes SSH configuration is missing for this company');
        }

        $this->sftp = new SFTP($host, $port);

        /** @var PrivateKey $key */
        $key = PublicKeyLoader::load($privateKey);

        if (! $this->sftp->login($user, $key)) {
            throw new ValidationException('Hermes SSH authentication failed');
        }
    }

    /**
     * BC alias — callers that used getHermesHome() continue to work.
     */
    public function getHermesHome(): string
    {
        return $this->getProviderHome();
    }
}
