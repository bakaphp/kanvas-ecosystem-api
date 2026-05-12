<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ArtifactsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CommunicationChannelTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyInformationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyIsHolidayTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompanyWorkHoursTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CompletionStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ContactCheckerTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\HandOffTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadIntentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadRefTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SimilarVehiclesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehicleInterestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\VehicleTradeInTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

class SalesNeuronAgentTest extends TestCase
{
    use DatabaseTransactions;

    protected Agent $agent;
    protected AgentType $agentType;

    private const CRM_TOOLS = [
        ArtifactsTool::class,
        CommunicationChannelTool::class,
        CompanyInformationTool::class,
        CompanyIsHolidayTool::class,
        CompanyWorkHoursTool::class,
        CompletionStatusTool::class,
        ContactCheckerTool::class,
        HandOffTool::class,
        LeadIntentTool::class,
        LeadRefTool::class,
        SimilarVehiclesTool::class,
        VehicleInterestTool::class,
        VehicleTradeInTool::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $this->agentType = AgentType::factory()
            ->withAppId($app->id)
            ->create([
                'name' => 'Sales',
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        $tools = $this->createCrmTools($app->id);

        $this->agentType->tools()->attach($tools->pluck('id')->toArray());

        $this->agent = Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create([
                'name' => 'Sales Agent',
                'agent_type_id' => $this->agentType->id,
                'soul' => 'You are a helpful sales assistant.',
                'instructions' => 'Always respond with: Hola Mundo',
                'output_format' => 'plain text',
            ]);

        $this->agent->selectedTools()->attach($tools->pluck('id')->toArray());
    }

    public function testSalesAgentTypeHasNeuronHandler(): void
    {
        $this->assertEquals('Sales', $this->agentType->name);
        $this->assertEquals('neuron', $this->agentType->provider);
        $this->assertEquals(SalesNeuronAgentStub::class, $this->agentType->handler);
    }

    public function testSalesAgentTypeHasAllCrmTools(): void
    {
        $attachedTools = $this->agentType->tools()->get();
        $this->assertCount(count(self::CRM_TOOLS), $attachedTools);

        $handlers = $attachedTools->pluck('handler')->toArray();
        foreach (self::CRM_TOOLS as $toolClass) {
            $this->assertContains($toolClass, $handlers);
        }
    }

    public function testSalesAgentHasAllCrmToolsSelected(): void
    {
        $selectedTools = $this->agent->selectedTools()->get();
        $this->assertCount(count(self::CRM_TOOLS), $selectedTools);

        $handlers = $selectedTools->pluck('handler')->toArray();
        foreach (self::CRM_TOOLS as $toolClass) {
            $this->assertContains($toolClass, $handlers);
        }
    }

    public function testNeuronAgentLoadsToolsFromCapabilityProvider(): void
    {
        $stub = new SalesNeuronAgentStub();
        $stub->setConfiguration($this->agent);

        // ContactCheckerTool requires a Message constructor arg and is skipped by the generic agent
        $instantiableCount = count(array_filter(
            self::CRM_TOOLS,
            fn ($class) => (new \ReflectionClass($class))->getConstructor()?->getNumberOfRequiredParameters() === 0
        ));

        $tools = $stub->getTools();
        $this->assertCount($instantiableCount, $tools);
        $this->assertGreaterThan(0, count($tools));
    }

    public function testChatWithSalesAgent(): void
    {
        $response = $this->graphQL('
            mutation($input: ChatSimpleInput!) {
                aiAgentChat(input: $input)
            }
        ', [
            'input' => [
                'agent_id' => (string) $this->agent->getId(),
                'session_id' => (string) Str::uuid(),
                'message' => 'Hola',
            ],
        ]);

        $response->assertGraphQLErrorFree();
        $responseText = $response->json('data.aiAgentChat');
        $this->assertNotEmpty($responseText);
        $this->assertStringContainsStringIgnoringCase('Hola Mundo', $responseText);
    }

    public function testSalesAgentInstructions(): void
    {
        $this->assertEquals('Always respond with: Hola Mundo', $this->agent->instructions);
        $this->assertEquals('You are a helpful sales assistant.', $this->agent->soul);
    }

    private function createCrmTools(int $appId): \Illuminate\Support\Collection
    {
        $created = collect();

        foreach (self::CRM_TOOLS as $handlerClass) {
            $tool = Tool::create([
                'uuid' => (string) Str::uuid(),
                'apps_id' => 0,
                'name' => class_basename($handlerClass),
                'description' => 'CRM tool: ' . class_basename($handlerClass),
                'tool_type' => 'crm',
                'handler' => $handlerClass,
                'frameworks' => ['neuron'],
                'version' => '1.0.0',
                'is_active' => true,
                'is_deleted' => false,
            ]);

            $created->push($tool);
        }

        return $created;
    }
}
