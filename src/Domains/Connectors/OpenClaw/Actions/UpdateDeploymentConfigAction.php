<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseUpdateDeploymentConfigAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;
use stdClass;

class UpdateDeploymentConfigAction extends BaseUpdateDeploymentConfigAction
{
    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }

    /**
     * Decoded as objects first so an empty `{}` survives the round trip: an associative decode turns
     * it into `[]`, and openclaw.json has several (`models` allowlist entries, `skills.entries`) that
     * the runtime's schema expects to stay objects.
     */
    #[Override]
    protected function decodeConfig(string $raw): array
    {
        $decoded = self::toArrayKeepingEmptyObjects(json_decode($raw));

        if (! is_array($decoded)) {
            throw new ValidationException('Invalid JSON config provided');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function toArrayKeepingEmptyObjects(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);

            return $properties === []
                ? $value
                : array_map(self::toArrayKeepingEmptyObjects(...), $properties);
        }

        if (is_array($value)) {
            return array_map(self::toArrayKeepingEmptyObjects(...), $value);
        }

        return $value;
    }

    #[Override]
    protected function encodeConfig(array $config): string
    {
        return (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
