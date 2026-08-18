<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\DataTransferObject;

/**
 * The remote agent definition we push to `/v1/agents`.
 *
 * A plain value object, not a Spatie Data class: it never rides a queue, and {@see fingerprint()}
 * needs a canonical serialization that Spatie's transformers would complicate.
 *
 * The fingerprint hashes the exact payload we would send, so comparing it against the value stored
 * on the agent says whether anything the vendor cares about changed. Unchanged means we skip the
 * call — otherwise every chat turn would mint a new remote version.
 */
final class ClaudeAgentSpec
{
    /**
     * @param list<array<string, mixed>> $tools
     * @param list<array<string, mixed>> $mcpServers
     */
    public function __construct(
        public readonly string $name,
        public readonly string $model,
        public readonly ?string $system = null,
        public readonly ?string $description = null,
        public readonly array $tools = [],
        public readonly array $mcpServers = [],
    ) {
    }

    /**
     * Optional keys are omitted rather than sent as null, so a null and an absent field don't
     * fingerprint differently for the same effective agent.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'name' => $this->name,
            'model' => $this->model,
        ];

        if ($this->system !== null && $this->system !== '') {
            $payload['system'] = $this->system;
        }

        if ($this->description !== null && $this->description !== '') {
            $payload['description'] = $this->description;
        }

        if ($this->tools !== []) {
            $payload['tools'] = $this->tools;
        }

        if ($this->mcpServers !== []) {
            $payload['mcp_servers'] = $this->mcpServers;
        }

        return $payload;
    }

    /** Key order is a source-code detail; letting it move the hash would version on a reorder. */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode(self::canonicalize($this->toPayload())));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        // List order IS semantic (tool precedence), so only associative arrays get sorted.
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }
}
