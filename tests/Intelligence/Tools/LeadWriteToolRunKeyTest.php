<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddLeadNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetLeadStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateLeadTool;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

class LeadWriteToolRunKeyTest extends TestCase
{
    public function testPerLeadWriteToolsKeyRunsPerInputsNotPerToolName(): void
    {
        $tools = [
            new UpdateLeadTool(),
            new SetLeadStatusTool(),
            new AddLeadNoteTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertInstanceOf(HasRunKey::class, $tool, $tool::class . ' must track runs by inputs.');

            $keyA = $tool->setInputs(['lead_id' => 754175])->getRunKey();
            $keyB = $tool->setInputs(['lead_id' => 753871])->getRunKey();
            $keyAAgain = $tool->setInputs(['lead_id' => 754175])->getRunKey();

            $this->assertNotEquals($keyA, $keyB, $tool::class . ': distinct leads must not share a run budget.');
            $this->assertEquals($keyA, $keyAAgain, $tool::class . ': identical calls must collapse to one key.');
        }
    }
}
