<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreatePersonTool;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

class CrmCreateToolRunKeyTest extends TestCase
{
    /**
     * A batch-sourcing turn ("create a lead for each of these 12 prospects") used to die on the 10th
     * record with ToolRunsExceededException, because the run budget was keyed on the tool name
     * (KANVAS-ECOSYSTEM-6A1). Distinct records must not share a budget; a repeated identical call must.
     */
    public function testCrmCreateToolsKeyRunsPerInputsNotPerToolName(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tools = [
            new CreateLeadTool($app, $company, $user),
            new CreateDealTool($app, $company, $user),
            new CreatePersonTool(),
            new CreateOrganizationTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertInstanceOf(HasRunKey::class, $tool, $tool::class . ' must track runs by inputs.');

            $keyA = $tool->setInputs(['title' => 'Caribetrans', 'firstname' => 'Claudia', 'name' => 'Caribetrans'])->getRunKey();
            $keyB = $tool->setInputs(['title' => 'Ryder', 'firstname' => 'Marcos', 'name' => 'Ryder'])->getRunKey();
            $keyAAgain = $tool->setInputs(['title' => 'Caribetrans', 'firstname' => 'Claudia', 'name' => 'Caribetrans'])->getRunKey();

            $this->assertNotEquals($keyA, $keyB, $tool::class . ': distinct records must not share a run budget.');
            $this->assertEquals($keyA, $keyAAgain, $tool::class . ': identical calls must collapse to one key.');
        }
    }
}
