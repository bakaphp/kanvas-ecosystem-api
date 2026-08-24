<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindDealsBulkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindLeadsBulkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindLeadsByTraitsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPeopleBulkTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\FindPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetPersonTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListOrganizationPeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListPeopleTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchDealsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SearchLeadsTool;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

class ReadToolRunKeyTest extends TestCase
{
    public function testReadLookupToolsKeyRunsPerInputsNotPerToolName(): void
    {
        $tools = [
            new FindPersonTool(),
            new FindPeopleBulkTool(),
            new FindLeadsBulkTool(),
            new FindDealsBulkTool(),
            new SearchLeadsTool(),
            new ListPeopleTool(),
            new ListOrganizationPeopleTool(),
            new GetPersonTool(),
            new SearchDealsTool(),
            new FindLeadsByTraitsTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertInstanceOf(HasRunKey::class, $tool, $tool::class . ' must track runs by inputs.');

            $keyA = $tool->setInputs(['query' => 'grupofamilia'])->getRunKey();
            $keyB = $tool->setInputs(['query' => 'essity'])->getRunKey();
            $keyAAgain = $tool->setInputs(['query' => 'grupofamilia'])->getRunKey();

            $this->assertNotEquals($keyA, $keyB, $tool::class . ': distinct queries must not share a run budget.');
            $this->assertEquals($keyA, $keyAAgain, $tool::class . ': identical calls must collapse to one key.');
        }
    }
}
