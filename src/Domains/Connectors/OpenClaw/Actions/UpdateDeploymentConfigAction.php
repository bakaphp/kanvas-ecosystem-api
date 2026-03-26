<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Deep-merge a partial JSON config into the deployment's openclaw.json and restart.
 *
 * The caller sends only the keys they want to change — unchanged keys
 * (gateway, auth, tools, etc.) are preserved via recursive merge.
 */
class UpdateDeploymentConfigAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected string $configJson,
    ) {
    }

    public function execute(): bool
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot update config on a deployment that is not running');
        }

        /** @var array<string, mixed>|null $patch */
        $patch = json_decode($this->configJson, true);

        if (! is_array($patch)) {
            throw new ValidationException('Invalid JSON config provided');
        }

        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $configPath = $this->deployment->home_directory . '/.openclaw/openclaw.json';
            $currentRaw = trim($client->readFile($configPath));

            /** @var array<string, mixed> $current */
            $current = json_decode($currentRaw, true) ?? [];

            $merged = $this->deepMerge($current, $patch);
            $mergedJson = (string) json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $client->writeFileAsUser(
                $configPath,
                $mergedJson,
                $this->deployment->system_user,
            );

            // Ensure node user inside container can read the file
            $client->exec(
                'sudo chown 1000:1000 ' . escapeshellarg($configPath)
            );

            $openclawDir = $this->deployment->home_directory . '/.openclaw';
            $client->exec(
                'sudo -u ' . escapeshellarg($this->deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $openclawDir . ' && docker compose restart 2>&1')
            );
        } finally {
            $client->disconnect();
        }

        return true;
    }

    /**
     * Recursively merge two arrays. Values from $patch override $base.
     * Arrays with sequential integer keys (lists) are replaced, not merged.
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
