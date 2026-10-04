<?php

declare(strict_types=1);

namespace Tests\Traits;

use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\TrackByInputs;

/**
 * The run key hashes declared inputs only, so a test that feeds a tool a name it does not declare
 * collapses every call to one key and asserts nothing. Each tool is probed on its own first property.
 */
trait AssertsToolRunKeys
{
    protected function assertRunKeyFollowsInputs(ToolInterface $tool): void
    {
        $this->assertContains(
            TrackByInputs::class,
            class_uses_recursive($tool),
            $tool->getName() . ' must key its run budget by inputs.'
        );

        $properties = $tool->getProperties();

        if ($properties === []) {
            return;
        }

        $input = $properties[0]->getName();

        $first = $tool->setInputs([$input => 'first-value'])->getRunKey();
        $second = $tool->setInputs([$input => 'second-value'])->getRunKey();
        $repeat = $tool->setInputs([$input => 'first-value'])->getRunKey();

        $this->assertNotSame($first, $second, $tool->getName() . ': distinct inputs must not share a run budget.');
        $this->assertSame($first, $repeat, $tool->getName() . ': identical calls must collapse to one key so a loop is still capped.');
    }
}
