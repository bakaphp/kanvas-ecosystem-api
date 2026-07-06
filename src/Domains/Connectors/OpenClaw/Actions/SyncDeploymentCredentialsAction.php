<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\OpenClaw\Services\DockerComposeBuilderService;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseSyncDeploymentCredentialsAction;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilderService;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

class SyncDeploymentCredentialsAction extends BaseSyncDeploymentCredentialsAction
{
    #[Override]
    protected function getProviderConfig(): ProviderConfig
    {
        return SshClient::makeProviderConfig();
    }

    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }

    #[Override]
    protected function getDockerComposeBuilder(): BaseDockerComposeBuilderService
    {
        return new DockerComposeBuilderService();
    }

    /**
     * OpenClaw's gateway reads the channel token from `channels.slack.botToken` (and the
     * `plugins.entries.*.enabled` flags) inside openclaw.json — the env var is only
     * belt-and-suspenders. Merge the freshly-built channels block into the on-disk config so
     * a rotated/removed token actually reaches the plugin, preserving every other key
     * (model, tools, and any admin `updateConfig` patches) rather than regenerating wholesale.
     */
    #[Override]
    protected function syncProviderCredentialFiles(
        BaseSshClient $client,
        AgentDeployment $deployment,
        Agent $agent,
        AppInterface $app,
        string $gatewayToken,
    ): void {
        $config = $this->getProviderConfig();
        $configPath = $deployment->home_directory . '/.' . $config->dotDir . '/' . $config->configFilename;

        // sudo-backed read: .openclaw/ is 0700 owned by the container UID; a plain SFTP read
        // returns '' and we'd clobber the whole config with only the channels block.
        $raw = trim($client->readFileAsUser($configPath));

        /** @var array<string, mixed> $current */
        $current = $raw === '' ? [] : (array) json_decode($raw, true);

        $channels = $this->getDockerComposeBuilder()->buildChannelConfig($agent);

        if ($channels === []) {
            unset($current['channels']);
        } else {
            $current['channels'] = $channels;
        }

        /** @var array<string, mixed> $plugins */
        $plugins = is_array($current['plugins'] ?? null) ? $current['plugins'] : [];

        /** @var array<string, mixed> $entries */
        $entries = is_array($plugins['entries'] ?? null) ? $plugins['entries'] : [];
        unset($entries['slack'], $entries['telegram']);
        foreach (['slack', 'telegram'] as $channel) {
            if (isset($channels[$channel])) {
                $entries[$channel] = ['enabled' => true];
            }
        }

        $plugins['entries'] = $entries === [] ? (object) [] : $entries;
        $current['plugins'] = $plugins;

        // openclaw.json holds merged, non-regenerable user config (updateConfig patches), so
        // snapshot it before the read-modify-write in case the merge/encode goes wrong.
        $this->backupRemoteFile($client, $configPath, $deployment->system_user);

        $client->writeFileAsUser(
            $configPath,
            (string) json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $deployment->system_user,
        );

        // node user inside the container runs as UID 1000 and must be able to read it back.
        $client->exec('sudo chown 1000:1000 ' . escapeshellarg($configPath));
    }
}
