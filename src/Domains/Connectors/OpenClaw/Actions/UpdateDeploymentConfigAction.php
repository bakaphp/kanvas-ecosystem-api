<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseUpdateDeploymentConfigAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

/**
 * OpenClaw concrete — uses the JSON codec (openclaw.json).
 */
class UpdateDeploymentConfigAction extends BaseUpdateDeploymentConfigAction
{
    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }

    #[Override]
    protected function decodeConfig(string $raw): array
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new ValidationException('Invalid JSON config provided');
        }

        return $decoded;
    }

    #[Override]
    protected function encodeConfig(array $config): string
    {
        return (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
