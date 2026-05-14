<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseUpdateDeploymentConfigAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Hermes concrete — uses the YAML codec (config.yaml).
 *
 * Hermes' config is structured YAML (nested maps for gateway/auth/tools/etc.). We accept
 * either YAML or JSON as the payload format: JSON is a strict subset of YAML 1.2 so
 * clients that send JSON still parse cleanly.
 */
class UpdateDeploymentConfigAction extends BaseUpdateDeploymentConfigAction
{
    /**
     * Use enough nesting depth for Hermes' deeply nested config (skills, model arrays,
     * provider profiles). 8 levels covers the documented structure with room to spare.
     */
    private const int YAML_INLINE_DEPTH = 8;
    private const int YAML_INDENT = 2;

    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }

    #[Override]
    protected function decodeConfig(string $raw): array
    {
        try {
            $decoded = Yaml::parse($raw);
        } catch (ParseException $e) {
            throw new ValidationException('Invalid YAML/JSON config provided: ' . $e->getMessage());
        } catch (Throwable $e) {
            throw new ValidationException('Failed to parse config payload: ' . $e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new ValidationException('Config payload must decode to a mapping, got ' . gettype($decoded));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    #[Override]
    protected function encodeConfig(array $config): string
    {
        return Yaml::dump(
            $config,
            self::YAML_INLINE_DEPTH,
            self::YAML_INDENT,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        );
    }
}
