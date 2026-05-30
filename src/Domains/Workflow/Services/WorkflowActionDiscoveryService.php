<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Services;

use Baka\Discovery\AttributeClassDiscovery;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\KanvasActivity;
use Override;
use ReflectionClass;

/**
 * @extends AttributeClassDiscovery<array{class: class-string, name: string, description: ?string}>
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

    #[Override]
    protected function toEntry(ReflectionClass $reflection, object $attribute): array
    {
        /** @var WorkflowAction $attribute */
        $fqcn = $reflection->getName();

        return [
            'class' => $fqcn,
            'name' => $attribute->name ?? class_basename($fqcn),
            'description' => $attribute->description,
        ];
    }
}
