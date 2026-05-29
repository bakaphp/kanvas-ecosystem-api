<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Attributes;

use Attribute;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Marks an agent tool (Laravel or Neuron) for sync into the `nervous_system_tools`
 * catalog via `kanvas:nervous-system:sync-tools`. All fields are optional — the
 * discovery service derives framework from the namespace, category from the folder,
 * and description from the tool itself when omitted.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AgentTool
{
    /**
     * @param string[] $frameworks CapabilityFrameworkEnum values; derived from the namespace when empty
     * @param string[]|null $requiresPermission Bouncer abilities required to use the tool
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $category = null,
        public readonly array $frameworks = [],
        public readonly string $toolType = 'system',
        public readonly string $version = '1.0.0',
        public readonly ?array $requiresPermission = null,
    ) {
    }

    /**
     * Read the attribute off a tool class or instance, or null if it carries none.
     *
     * @param class-string|object $tool
     */
    public static function fromClass(string|object $tool): ?self
    {
        $attributes = new ReflectionClass($tool)->getAttributes(self::class, ReflectionAttribute::IS_INSTANCEOF);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
