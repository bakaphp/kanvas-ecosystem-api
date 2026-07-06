<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Services\BaseDockerComposeBuilderService;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Re-materializes a running deployment's docker-compose.yml from the agent's current state
 * and recreates the container with `docker compose up -d`.
 *
 * Why `up -d` and not `docker compose restart`: channel credentials (Slack/Telegram tokens)
 * are injected as container env vars into docker-compose.yml at launch time (see
 * BaseLaunchAgentOnMachineAction). `restart` reuses the existing container definition, so a
 * rotated token never reaches the process — only `up -d`, which diffs the compose file and
 * recreates the container, applies new env. This is the post-launch counterpart to that
 * one-time env injection, letting keys updated on a live agent actually take effect.
 *
 * Runtimes differ in WHERE the channel token lives: Hermes reads it from the compose env var
 * (nothing else to do), while OpenClaw reads it from `channels.slack.botToken` in its JSON
 * runtime config. The {@see syncProviderCredentialFiles()} hook lets a provider write the
 * token into its own config file too — a no-op for Hermes, a channels-merge for OpenClaw.
 */
abstract class BaseSyncDeploymentCredentialsAction
{
    private const int UP_TIMEOUT_SECONDS = 300;

    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    abstract protected function getProviderConfig(): ProviderConfig;

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    abstract protected function getDockerComposeBuilder(): BaseDockerComposeBuilderService;

    public function execute(): AgentDeployment
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot sync credentials to a deployment that is not running');
        }

        $deployment = $this->deployment;
        $agent = $deployment->agent;

        if (! $agent instanceof Agent) {
            throw new ValidationException('Deployment has no agent to sync credentials from');
        }

        /** @var Apps $app */
        $app = Apps::getById($deployment->apps_id);
        $config = $this->getProviderConfig();
        $providerDir = $deployment->home_directory . '/.' . $config->dotDir;

        // Reuse the agent's existing gateway token so the container's API key stays stable
        // across the recreate — this is the canonical agent-scoped token launch persisted.
        $gatewayToken = (string) $agent->get($config->gatewayTokenCustomFieldKey);

        $builder = $this->getDockerComposeBuilder();
        $client = $this->createSshClient($deployment->machine);

        try {
            // Channel tokens (Slack/Telegram) are injected as container env vars into
            // docker-compose.yml — Hermes reads them straight from the env, not config.yaml.
            // The compose file is fully derived (no user edits), so regenerating it wholesale
            // is safe; `docker compose up -d` below recreates the container so the new env
            // takes effect (a plain `restart` reuses the old env and would not).
            //
            // Keep Hermes' token in env ONLY. Its gateway merges + dedups Slack tokens across
            // SLACK_BOT_TOKEN, config.yaml `platforms.slack.token`, and slack_tokens.json, so a
            // second source would let a rotated-out token survive the merge and keep connecting.
            // (https://hermes-agent.nousresearch.com/docs/user-guide/messaging/slack)
            $this->backupRemoteFile($client, $providerDir . '/docker-compose.yml', $deployment->system_user);
            $client->writeFileAsUser(
                $providerDir . '/docker-compose.yml',
                $builder->buildDockerCompose($deployment, $gatewayToken, $app, $agent),
                $deployment->system_user,
            );

            $this->syncProviderCredentialFiles($client, $deployment, $agent, $app, $gatewayToken);

            $client->exec(
                'sudo -u ' . escapeshellarg($deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose up -d 2>&1'),
                self::UP_TIMEOUT_SECONDS,
            );

            $deployment->status = DeploymentStatusEnum::RUNNING->value;
            $deployment->saveOrFail();
        } finally {
            $client->disconnect();
        }

        return $deployment;
    }

    /**
     * Write channel credentials into the provider's own runtime config file, when it keeps
     * them there. Default no-op: Hermes reads the token from the compose env var alone, so the
     * container recreate is enough. OpenClaw overrides this to merge the freshly-built
     * `channels` block into its JSON config (preserving every other key).
     */
    protected function syncProviderCredentialFiles(
        SshClient $client,
        AgentDeployment $deployment,
        Agent $agent,
        AppInterface $app,
        string $gatewayToken,
    ): void {
    }

    /**
     * Snapshot a remote file to `<path>.bak-{UTC ISO}` before we overwrite it, so a bad render
     * can be rolled back on the box (cp the .bak back + `docker compose up -d`) without replaying
     * the whole pipeline. No-op when the file doesn't exist yet. Mirrors the backup convention in
     * BasePushDailyLearningContextAction.
     */
    protected function backupRemoteFile(SshClient $client, string $path, string $systemUser): void
    {
        $backupPath = $path . '.bak-' . now('UTC')->format('Ymd\THis\Z');

        $client->exec(
            'sudo test -f ' . escapeshellarg($path)
            . ' && sudo cp ' . escapeshellarg($path) . ' ' . escapeshellarg($backupPath)
            . ' && sudo chown ' . escapeshellarg($systemUser . ':' . $systemUser) . ' ' . escapeshellarg($backupPath)
            . ' || true'
        );
    }
}
