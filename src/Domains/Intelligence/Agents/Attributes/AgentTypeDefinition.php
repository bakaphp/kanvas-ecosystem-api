<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Attributes;

use Attribute;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Marks an agent handler class (Neuron, Laravel-AI, ADK, OpenClaw, ...) as a global
 * agent-type template, synced into the `agent_types` catalog (apps_id=0) via
 * `kanvas:intelligence:sync-agent-types`. The FQCN of the annotated class becomes
 * the type's `handler`.
 *
 * All fields are optional — the discovery service derives `name` from the class
 * basename, `provider` from the handler's base class, and `instructions` from the
 * handler's own `instructions()` method when they're omitted here.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AgentTypeDefinition
{
    /**
     * @param array<string, mixed> $config Default config JSON for the type
     * @param list<string> $requires What a human must still set on an agent of this type before it
     *                               can do its job, one plain sentence each. Read at hire time, so a
     *                               half-configured hire is reported rather than discovered later.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $provider = null,
        public readonly ?string $soul = null,
        public readonly ?string $outputFormat = null,
        public readonly ?string $role = null,
        public readonly bool $isPublished = true,
        public readonly bool $isDefault = false,
        public readonly bool $isMultiAgent = false,
        public readonly int $weight = 0,
        public readonly array $config = [],
        public readonly array $requires = [],
    ) {
    }

    /**
     * @param class-string|object $handler
     */
    public static function fromClass(string|object $handler): ?self
    {
        $attributes = new ReflectionClass($handler)->getAttributes(self::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
