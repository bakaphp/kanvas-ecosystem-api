<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddLeadNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
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
            $this->assertRunKeyFollowsInputs($tool);
        }
    }
}
