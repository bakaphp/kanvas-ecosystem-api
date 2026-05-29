<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Discovery\AttributeClassDiscovery;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\KanvasAgentAsTool;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use NeuronAI\Tools\Tool as NeuronTool;
use Override;
use ReflectionClass;
use Throwable;

/**
 * @extends AttributeClassDiscovery<array{
 *     class: class-string,
 *     name: string,
 *     description: ?string,
 *     category: ?string,
 *     frameworks: list<string>,
 *     toolType: string,
 *     version: string,
 *     requiresPermission: ?array
 * }>
 */
class AgentToolDiscoveryService extends AttributeClassDiscovery
{
    #[Override]
    protected function attributeClass(): string
    {
        return AgentTool::class;
    }

    #[Override]
    protected function isCandidate(ReflectionClass $reflection): bool
    {
        return $reflection->implementsInterface(KanvasToolInterface::class)
            || $reflection->isSubclassOf(NeuronTool::class)
            || $reflection->isSubclassOf(KanvasAgentAsTool::class);
    }

    #[Override]
    protected function toEntry(ReflectionClass $reflection, object $attribute): array
    {
        /** @var AgentTool $attribute */
        $fqcn = $reflection->getName();

        $frameworks = array_values(array_filter(
            $attribute->frameworks !== []
                ? $attribute->frameworks
                : [$this->frameworkFromNamespace($fqcn)],
        ));

        return [
            'class' => $fqcn,
            'name' => $attribute->name ?? Str::headline(Str::beforeLast(class_basename($fqcn), 'Tool')),
            'description' => $attribute->description ?? $this->resolveDescription($reflection),
            'category' => $attribute->category ?? $this->categoryFromNamespace($fqcn),
            'frameworks' => $frameworks,
            'toolType' => $attribute->toolType,
            'version' => $attribute->version,
            'requiresPermission' => $attribute->requiresPermission,
        ];
    }

    private function frameworkFromNamespace(string $fqcn): ?string
    {
        return match (true) {
            str_contains($fqcn, '\\Neuron\\') => CapabilityFrameworkEnum::NEURON->value,
            str_contains($fqcn, '\\Laravel\\') => CapabilityFrameworkEnum::LARAVEL->value,
            default => null,
        };
    }

    private function categoryFromNamespace(string $fqcn): ?string
    {
        return match (true) {
            str_contains($fqcn, '\\Inventory\\') => 'inventory',
            str_contains($fqcn, '\\Guild\\'),
            str_contains($fqcn, '\\CRM\\'),
            str_contains($fqcn, '\\SubAgents\\') => 'crm',
            default => null,
        };
    }

    /**
     * Read the tool's own description when the attribute omits one. Only
     * instantiates parameterless tools; any failure falls back to null.
     */
    private function resolveDescription(ReflectionClass $reflection): ?string
    {
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        try {
            $instance = $reflection->newInstance();

            if (method_exists($instance, 'description')) {
                $value = (string) $instance->description();

                return $value !== '' ? $value : null;
            }

            if (method_exists($instance, 'getDescription')) {
                $value = (string) $instance->getDescription();

                return $value !== '' ? $value : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
