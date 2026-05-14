<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Deep-merge a partial config patch into the deployment's runtime config file, then restart.
 *
 * The caller sends only the keys they want to change; unchanged keys (gateway, auth, tools,
 * model selection, etc.) are preserved via recursive merge. The serialization format (JSON for
 * OpenClaw, YAML for Hermes) is delegated to {@see decodeConfig} / {@see encodeConfig} so each
 * provider drops its own codec into the abstract slot — the merge logic stays runtime-agnostic.
 */
abstract class BaseUpdateDeploymentConfigAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected string $configPayload,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    /**
     * Decode the on-disk + incoming config into PHP arrays for merging. Implementations
     * throw ValidationException on parse failure with a clear message.
     *
     * @return array<string, mixed>
     */
    abstract protected function decodeConfig(string $raw): array;

    /**
     * Re-encode the merged config back to the serialized form written to disk. Result should
     * be pretty-printed so humans can diff it inside the container.
     *
     * @param array<string, mixed> $config
     */
    abstract protected function encodeConfig(array $config): string;

    public function execute(): bool
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot update config on a deployment that is not running');
        }

        $patch = $this->decodeConfig($this->configPayload);

        $client = $this->createSshClient($this->deployment->machine);

        try {
            $providerConfig = $client::makeProviderConfig();
            $providerDir = $this->deployment->home_directory . '/.' . $providerConfig->dotDir;
            $configPath = $providerDir . '/' . $providerConfig->configFilename;

            $currentRaw = trim($client->readFile($configPath));
            $current = $currentRaw === '' ? [] : $this->decodeConfig($currentRaw);

            $merged = $this->deepMerge($current, $patch);

            $client->writeFileAsUser(
                $configPath,
                $this->encodeConfig($merged),
                $this->deployment->system_user,
            );

            // Ensure node user inside the container (UID 1000) can read the file
            $client->exec(
                'sudo chown 1000:1000 ' . escapeshellarg($configPath)
            );

            $client->exec(
                'sudo -u ' . escapeshellarg($this->deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose restart 2>&1'),
                120,
            );
        } finally {
            $client->disconnect();
        }

        return true;
    }

    /**
     * Recursively merge two assoc arrays. Values from $patch override $base. Lists (arrays
     * with sequential integer keys) are replaced wholesale, not concatenated.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    protected function deepMerge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && $this->isAssociative($value)
            ) {
                /** @var array<string, mixed> $baseValue */
                $baseValue = $base[$key];
                /** @var array<string, mixed> $patchValue */
                $patchValue = $value;
                $base[$key] = $this->deepMerge($baseValue, $patchValue);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param array<mixed> $array
     */
    protected function isAssociative(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
