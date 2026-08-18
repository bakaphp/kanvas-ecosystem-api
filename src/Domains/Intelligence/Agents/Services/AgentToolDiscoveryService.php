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
        // Dynamic tools that need runtime state (e.g. DynamicSubAgent wraps an Agent
        // record via a required constructor) aren't static catalog entries — they can't
        // be synced, so they're not required to carry the attribute either.
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        return $reflection->implementsInterface(KanvasToolInterface::class)
            || $reflection->isSubclassOf(NeuronTool::class)
            || $reflection->isSubclassOf(KanvasAgentAsTool::class);
    }

    #[Override]
    protected function toEntry(ReflectionClass $reflection, object $attribute): array
    {
        /** @var AgentTool $attribute */
        $fqcn = $reflection->getName();

        $frameworks = $this->withHostedFrameworks(array_values(array_filter(
            $attribute->frameworks !== []
                ? $attribute->frameworks
                : [$this->frameworkFromNamespace($fqcn)],
        )));

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

    /**
     * A NeuronAI tool is also runnable by a Claude Managed Agent: the custom-tool bridge hands the
     * call back in-process and executes the very same object. Tagging both keeps the catalog honest
     * about that — and, more practically, `CapabilityProvider::getActiveTools()` and the grant UI
     * filter by the agent type's provider, so without the `claude` tag a hosted agent could not be
     * granted a single tool.
     *
     * @param list<string> $frameworks
     * @return list<string>
     */
    private function withHostedFrameworks(array $frameworks): array
    {
        if (! in_array(CapabilityFrameworkEnum::NEURON->value, $frameworks, true)) {
            return $frameworks;
        }

        if (in_array(CapabilityFrameworkEnum::CLAUDE->value, $frameworks, true)) {
            return $frameworks;
        }

        $frameworks[] = CapabilityFrameworkEnum::CLAUDE->value;

        return $frameworks;
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
            str_contains($fqcn, '\\Apollo\\'),
            str_contains($fqcn, '\\SubAgents\\') => 'crm',
            str_contains($fqcn, '\\Souk\\'),
            str_contains($fqcn, '\\Sales\\') => 'commerce',
            str_contains($fqcn, '\\Accounting\\') => 'accounting',
            str_contains($fqcn, '\\NervousSystem\\') => 'nervous_system',
            str_contains($fqcn, '\\HumanResources\\') => 'human_resources',
            str_contains($fqcn, '\\Events\\') => 'events',
            str_contains($fqcn, '\\Templates\\') => 'templates',
            str_contains($fqcn, '\\News\\'),
            str_contains($fqcn, '\\Tavily\\'),
            str_contains($fqcn, '\\FinancialModelingPrep\\') => 'knowledge',
            str_contains($fqcn, '\\System\\'),
            str_contains($fqcn, '\\Common\\') => 'ecosystem',
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
