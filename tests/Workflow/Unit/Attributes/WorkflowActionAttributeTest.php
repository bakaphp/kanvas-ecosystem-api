<?php

declare(strict_types=1);

namespace Tests\Workflow\Unit\Attributes;

use Kanvas\Workflow\Attributes\WorkflowAction;
use ReflectionClass;
use Tests\TestCase;

#[WorkflowAction]
final class WorkflowActionAttributeDefaultFixture
{
}

#[WorkflowAction(name: 'Custom Name', description: 'Custom Description')]
final class WorkflowActionAttributeOverrideFixture
{
}

final class WorkflowActionAttributeTest extends TestCase
{
    public function testAttributeIsReadableViaReflectionWithDefaults(): void
    {
        $attributes = new ReflectionClass(WorkflowActionAttributeDefaultFixture::class)
            ->getAttributes(WorkflowAction::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertNull($instance->name);
        $this->assertNull($instance->description);
    }

    public function testAttributeAcceptsNameAndDescriptionOverrides(): void
    {
        $attributes = new ReflectionClass(WorkflowActionAttributeOverrideFixture::class)
            ->getAttributes(WorkflowAction::class);

        $instance = $attributes[0]->newInstance();
        $this->assertSame('Custom Name', $instance->name);
        $this->assertSame('Custom Description', $instance->description);
    }
}
