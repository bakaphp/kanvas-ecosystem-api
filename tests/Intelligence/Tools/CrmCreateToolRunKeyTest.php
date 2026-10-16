<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateDealTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateLeadTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreatePersonTool;
use Tests\TestCase;
use Tests\Traits\AssertsToolRunKeys;

class CrmCreateToolRunKeyTest extends TestCase
{
    use AssertsToolRunKeys;

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
            $this->assertRunKeyFollowsInputs($tool);
        }
    }
}
