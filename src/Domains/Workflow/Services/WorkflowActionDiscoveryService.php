<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Services;

use Baka\Discovery\AttributeClassDiscovery;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Override;
use ReflectionClass;

/**
 * @extends AttributeClassDiscovery<array{
 *     class: class-string,
 *     name: string,
 *     description: ?string,
 *     kind: string,
 *     integration: ?string,
 *     requires_config: list<string>,
 *     params: array<string, string>,
 *     required_params: list<string>,
 * }>
 */
class WorkflowActionDiscoveryService extends AttributeClassDiscovery
{
    #[Override]
    protected function attributeClass(): string
    {
        return WorkflowAction::class;
    }

    #[Override]
    protected function isCandidate(ReflectionClass $reflection): bool
    {
        return $reflection->isSubclassOf(KanvasActivity::class)
            || $reflection->isSubclassOf(ProcessWebhookJob::class);
    }

    /**
     * Names are how a rule is assembled — `resolveAction()` matches on `LOWER(name)` — so two
     * handlers sharing one name means "Push Lead Activity" silently wires whichever connector the
     * database happens to return first. Seven connectors ship a `PushLeadActivity`.
     *
     * Colliding basenames are therefore qualified with the connector they belong to. Only the
     * collisions are touched: a name that is already unique, or was set explicitly on the attribute,
     * is left exactly as it is.
     *
     * @return list<array<string, mixed>>
     */
    #[Override]
    public function discover(): array
    {
        $entries = parent::discover();

        // Two passes, and the second cannot fail: prefixing the connector fixes every collision seen
        // in practice (seven connectors ship a PushLeadActivity), but two classes with the same
        // basename *inside one connector* would still tie. Those fall back to the namespace path,
        // which is unique because `model_name` is.
        foreach ([false, true] as $useFullPath) {
            $counts = array_count_values(array_column($entries, 'name'));

            if (max([1, ...array_values($counts)]) < 2) {
                break;
            }

            foreach ($entries as $index => $entry) {
                if (($counts[$entry['name']] ?? 0) < 2) {
                    continue;
                }

                $entries[$index]['name'] = $this->disambiguate($entry['class'], $useFullPath);
            }
        }

        return $entries;
    }

    /**
     * The connector a handler belongs to (or the namespace segment below the domain root), prefixed
     * to its class basename. `$useFullPath` widens that to the whole namespace when the connector
     * alone was not enough to tell two handlers apart.
     */
    protected function disambiguate(string $fqcn, bool $useFullPath): string
    {
        $segments = explode('\\', $fqcn);
        $basename = array_pop($segments);

        if ($useFullPath) {
            // Drop the vendor root; what is left is unique per class and still readable.
            $path = array_slice($segments, 1);

            return implode(' ', $path) . ' ' . $basename;
        }

        $connectorIndex = array_search('Connectors', $segments, true);
        $qualifier = $connectorIndex !== false
            ? ($segments[$connectorIndex + 1] ?? null)
            : ($segments[1] ?? null);

        return $qualifier !== null ? $qualifier . ' ' . $basename : $basename;
    }

    #[Override]
    protected function toEntry(ReflectionClass $reflection, object $attribute): array
    {
        /** @var WorkflowAction $attribute */
        $fqcn = $reflection->getName();

        return [
            'class' => $fqcn,
            'name' => $attribute->name ?? class_basename($fqcn),
            'description' => $attribute->description,
            'kind' => ($attribute->kind ?? $this->deriveKind($reflection))->value,
            'integration' => $attribute->integrationValue(),
            'requires_config' => $attribute->requiredConfigKeys(),
            'params' => $attribute->params,
            'required_params' => $attribute->requiredParamNames(),
        ];
    }

    /**
     * The base class already says which surface a class belongs to, so declaring it on every
     * attribute would be a second source of truth that can disagree with the first.
     */
    protected function deriveKind(ReflectionClass $reflection): ActionKindEnum
    {
        return $reflection->isSubclassOf(ProcessWebhookJob::class)
            ? ActionKindEnum::RECEIVER
            : ActionKindEnum::WORKFLOW;
    }
}
