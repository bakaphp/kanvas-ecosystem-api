<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode;

use Baka\Contracts\CompanyInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseClient;
use Override;

/**
 * Host access for coding sessions: `docker run`, `docker rm`, and the git that prepares a worktree.
 *
 * Only ever built with `fromMachine()`. There is no company-config path because a coding session has no
 * gateway, no CLI and no per-company runtime home — unlike OpenClaw, the container is disposable and
 * everything it needs is passed at `docker run`.
 */
class SshClient extends BaseClient
{
    #[Override]
    public static function makeProviderConfig(): ProviderConfig
    {
        return new ProviderConfig(
            providerName: 'opencode',
            containerPrefix: 'kanvas-coding-',
            queueName: 'agent-runtime',
            dotDir: 'opencode',
            configFilename: 'opencode.json',
            containerHomeDotDir: '/home/kanvasrun/.config/opencode',
            mjsPath: 'opencode',
            cliAlias: 'opencode',
            defaultSharedImageName: 'kanvas/opencode:1.18.32',
            defaultSharedImageDir: '/opt/kanvas-opencode',
            dirPlaceholder: '{{OPENCODE_DIR}}',
            gatewayTokenCustomFieldKey: 'OPENCODE_SERVER_PASSWORD',
            deploymentIdCustomFieldKey: 'OPENCODE_SESSION_ID',
            gatewayTokenConfigKey: 'opencode_server_password',
        );
    }

    #[Override]
    protected function buildFromCompanyConfig(CompanyInterface $company): void
    {
        throw new ValidationException(
            'Coding sessions connect to a machine, not to company SSH config — use SshClient::fromMachine().'
        );
    }
}
