<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddLeadNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use NeuronAI\Tools\TrackByInputs;
use Tests\TestCase;
use Tests\Traits\AssertsToolRunKeys;

class LeadWriteToolRunKeyTest extends TestCase
{
    use AssertsToolRunKeys;

    public function testPerLeadWriteToolsKeyRunsPerInputsNotPerToolName(): void
    {
        $tools = [
            new UpdateLeadTool(),
            new SetLeadStatusTool(),
            new AddLeadNoteTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertContains(TrackByInputs::class, class_uses_recursive($tool), $tool::class . ' must track runs by inputs.');

            $this->assertRunKeyFollowsInputs($tool);
        }
    }
}
