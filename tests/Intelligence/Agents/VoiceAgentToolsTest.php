<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\Voice\RunVoiceAgentToolAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Common\CurrentTimeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddLeadNoteTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CategorySearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ProductDetailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantDetailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\VariantSearchTool;
use Kanvas\Intelligence\Agents\Services\VoiceAgentSpecService;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use NeuronAI\Tools\Tool as NeuronTool;
use stdClass;
use Tests\TestCase;

/**
 * The voice runtime's contract for an agent's tools: every advertised tool says whether it is a
 * safe read, and a tool is run by the same name it was advertised under.
 */
final class VoiceAgentToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $testApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = Companies::getById(auth()->user()->getCurrentCompany()->getId());
    }

    public function testToolsAreWritesUnlessMarkedReadOnly(): void
    {
        foreach ([InventorySearchTool::class, CategorySearchTool::class, ProductDetailTool::class, VariantDetailTool::class, VariantSearchTool::class] as $readTool) {
            $this->assertTrue(AgentTool::isReadOnly($readTool), "{$readTool} should be read-only");
        }

        $this->assertFalse(AgentTool::isReadOnly(AddLeadNoteTool::class));
        $this->assertFalse(AgentTool::isReadOnly(DynamicallyNamedVoiceTool::class), 'no attribute means write');
    }

    public function testSpecAdvertisesReadOnlyAsABooleanOnEveryTool(): void
    {
        $agent = $this->agentWithTools([CurrentTimeTool::class, AddLeadNoteTool::class]);

        $tools = collect(new VoiceAgentSpecService($agent, $this->testApp)->compile()['tools'])->keyBy('name');

        $this->assertCount(2, $tools);
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('read_only', $tool);
            $this->assertIsBool($tool['read_only']);
        }
        $this->assertTrue($tools['get_current_time']['read_only']);
        $this->assertFalse($tools['add_lead_note']['read_only']);
    }

    public function testDeclaredToolNameIsReadWithoutBuildingTheTool(): void
    {
        $this->assertSame('inventory_search', RunVoiceAgentToolAction::declaredToolName(InventorySearchTool::class));
        $this->assertNull(RunVoiceAgentToolAction::declaredToolName(DynamicallyNamedVoiceTool::class));
        $this->assertNull(RunVoiceAgentToolAction::declaredToolName(stdClass::class));
    }

    public function testRunResolvesTheToolByItsHandlerName(): void
    {
        $agent = $this->agentWithTools([AddLeadNoteTool::class, CurrentTimeTool::class]);

        $result = new RunVoiceAgentToolAction($agent, 'get_current_time', ['timezone' => 'UTC'])->execute();

        $this->assertSame('UTC', $result['timezone']);
    }

    public function testRunStillResolvesAToolNamedAtConstruction(): void
    {
        $agent = $this->agentWithTools([CurrentTimeTool::class, DynamicallyNamedVoiceTool::class]);

        $result = new RunVoiceAgentToolAction($agent, 'dynamically_named_voice_tool', [])->execute();

        $this->assertSame(['ok' => true], $result);
    }

    public function testRunRejectsAToolTheAgentDoesNotHave(): void
    {
        $agent = $this->agentWithTools([CurrentTimeTool::class]);

        $this->expectException(ValidationException::class);

        new RunVoiceAgentToolAction($agent, 'add_lead_note', [])->execute();
    }

    /**
     * @param list<class-string<NeuronTool>> $handlers
     */
    private function agentWithTools(array $handlers): Agent
    {
        $agent = Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();

        $toolIds = array_map(fn (string $handler): int => new CreateToolAction(
            new ToolData(
                app: $this->testApp,
                name: 'voice-tool-' . uniqid(),
                frameworks: ['neuron'],
                toolType: ToolTypeEnum::CUSTOM,
                handler: $handler,
            ),
        )->execute()->getId(), $handlers);

        $agent->selectedTools()->sync($toolIds);

        return $agent;
    }
}

/**
 * A tool whose name is only known once it is built, with no AgentTool attribute.
 */
final class DynamicallyNamedVoiceTool extends NeuronTool
{
    public function __construct()
    {
        $this->name = 'dynamically_named_voice_tool';
        $this->description = 'Test tool named in its constructor.';
    }

    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
