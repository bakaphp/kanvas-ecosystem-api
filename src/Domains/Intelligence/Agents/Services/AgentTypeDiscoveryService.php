<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Discovery\AttributeClassDiscovery;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use Kanvas\Intelligence\Agents\Types\ADKAgent;
use Kanvas\Intelligence\Agents\Types\BaseAgent;
use Kanvas\Intelligence\Agents\Types\ClaudeManagedAgentHandler;
use Kanvas\Intelligence\Agents\Types\OpenClawAgentHandler;
use Override;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * @extends AttributeClassDiscovery<array{
 *     class: class-string,
 *     name: string,
 *     description: ?string,
 *     provider: ?string,
 *     soul: ?string,
 *     instructions: ?string,
 *     outputFormat: ?string,
 *     role: ?string,
 *     isPublished: bool,
 *     isDefault: bool,
 *     isMultiAgent: bool,
 *     weight: int,
 *     config: array
 * }>
 */
class AgentTypeDiscoveryService extends AttributeClassDiscovery
{
    /** @var list<class-string> Agent handler base classes that act as agent-type templates. */
    private const array HANDLER_BASES = [
        BaseAgent::class,
        BaseKanvasAgent::class,
        BaseRagAgent::class,
        KanvasLaravelAgent::class,
        ADKAgent::class,
        OpenClawAgentHandler::class,
        ClaudeManagedAgentHandler::class,
    ];

    #[Override]
    protected function attributeClass(): string
    {
        return AgentTypeDefinition::class;
    }

    #[Override]
    protected function isCandidate(ReflectionClass $reflection): bool
    {
        return array_any(
            self::HANDLER_BASES,
            fn (string $base) => $reflection->isSubclassOf($base),
        );
    }

    #[Override]
    protected function toEntry(ReflectionClass $reflection, object $attribute): array
    {
        /** @var AgentTypeDefinition $attribute */
        $fqcn = $reflection->getName();

        return [
            'class' => $fqcn,
            'name' => $attribute->name ?? Str::headline(class_basename($fqcn)),
            'description' => $attribute->description,
            'provider' => $attribute->provider ?? $this->providerFromHandler($reflection),
            'soul' => $attribute->soul,
            'instructions' => $this->instructionsFromHandler($reflection),
            'outputFormat' => $attribute->outputFormat,
            'role' => $attribute->role,
            'isPublished' => $attribute->isPublished,
            'isDefault' => $attribute->isDefault,
            'isMultiAgent' => $attribute->isMultiAgent,
            'weight' => $attribute->weight,
            'config' => $attribute->config,
        ];
    }

    /**
     * Snapshot the handler's own `instructions()` so the persona lives in the agent
     * class, not the attribute. Only safe for static handlers: role-driven agents
     * reach into an uninitialized Agent and emit warnings, which we promote to
     * throwables so they fall to null and keep computing their prompt per-turn.
     */
    private function instructionsFromHandler(ReflectionClass $reflection): ?string
    {
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        set_error_handler(static fn (int $severity, string $message): bool => throw new RuntimeException($message));

        try {
            $instance = $reflection->newInstance();
            if (! method_exists($instance, 'instructions')) {
                return null;
            }

            $value = trim((string) $instance->instructions());

            return $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Provider is metadata only — routing is by `instanceof` (see AgentChatKernel) —
     * so deriving it from the base class when the attribute omits it is best-effort.
     */
    private function providerFromHandler(ReflectionClass $reflection): ?string
    {
        return match (true) {
            $reflection->isSubclassOf(KanvasLaravelAgent::class) => AgentProviderEnum::LARAVEL->value,
            $reflection->isSubclassOf(ADKAgent::class) => AgentProviderEnum::ADK->value,
            $reflection->isSubclassOf(OpenClawAgentHandler::class) => AgentProviderEnum::OPENCLAW->value,
            $reflection->isSubclassOf(ClaudeManagedAgentHandler::class) => AgentProviderEnum::CLAUDE->value,
            $reflection->isSubclassOf(BaseAgent::class),
            $reflection->isSubclassOf(BaseKanvasAgent::class),
            $reflection->isSubclassOf(BaseRagAgent::class) => AgentProviderEnum::NEURON->value,
            default => null,
        };
    }
}
