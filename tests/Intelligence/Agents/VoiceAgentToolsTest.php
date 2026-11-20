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
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool as NeuronTool;
use NeuronAI\Tools\ToolProperty;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * The voice runtime's contract for an agent's tools: every advertised tool says whether it is a
 * safe read, and a tool is run by the same name it was advertised under.
 */
final class VoiceAgentToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'inventory'];

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

    public function testAToolCrashNeverReachesTheCallerAsRawText(): void
    {
        $agent = $this->agentWithTools([CrashingVoiceTool::class]);

        $result = new RunVoiceAgentToolAction($agent, 'crashing_voice_tool', ['kind' => 'crash'])->execute();

        $this->assertSame('error', $result['status']);
        $this->assertSame('tool_failed', $result['reason']);
        $this->assertStringNotContainsString('SQLSTATE', $result['message']);
    }

    public function testAValidationErrorIsPassedToTheModel(): void
    {
        $agent = $this->agentWithTools([CrashingVoiceTool::class]);

        $result = new RunVoiceAgentToolAction($agent, 'crashing_voice_tool', ['kind' => 'validation'])->execute();

        $this->assertSame('invalid_request', $result['reason']);
        $this->assertSame('That date is in the past.', $result['message']);
    }

    public function testVariantDetailNeedsACompany(): void
    {
        $result = new VariantDetailTool()->__invoke(variant_id: 1);

        $this->assertSame('error', $result['status']);
        $this->assertSame('no_tenant_context', $result['reason']);
    }

    public function testVariantDetailReadsOnlyTheAgentsCompany(): void
    {
        $variant = Products::withoutSyncingToSearch(fn () => Variants::withoutSyncingToSearch(
            fn () => Products::factory()->company($this->company->getId())->create()->variants()->first()
        ));
        $tool = new VariantDetailTool()->withContext($this->testApp, $this->company, auth()->user());

        $detail = $tool->__invoke(variant_id: $variant->getId());
        $this->assertSame($variant->getId(), $detail['id']);
        $this->assertArrayNotHasKey('cost', $detail['inventory'][0] ?? [], 'cost is internal; agents talk to customers');

        // The same id, owned by another company, reads nothing.
        Variants::withoutSyncingToSearch(
            fn () => $variant->forceFill(['companies_id' => $this->company->getId() + 1_000_000])
                ->saveQuietly()
        );

        $this->assertSame('not_found', $tool->__invoke(variant_id: $variant->getId())['status']);
    }

    public function testVariantDetailOpensAnotherCompanysVariantWhenTheAppSharesThem(): void
    {
        $variant = Products::withoutSyncingToSearch(fn () => Variants::withoutSyncingToSearch(
            fn () => Products::factory()->company($this->company->getId())->create()->variants()->first()
        ));
        Variants::withoutSyncingToSearch(
            fn () => $variant->forceFill(['companies_id' => $this->company->getId() + 1_000_000])
                ->saveQuietly()
        );
        $tool = new VariantDetailTool()->withContext($this->testApp, $this->company, auth()->user());
        $originalFlag = $this->testApp->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);

        try {
            // A marketplace app: list_available_products offers every company's products.
            $this->testApp->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, true);

            $this->assertSame($variant->getId(), $tool->__invoke(variant_id: $variant->getId())['id']);
        } finally {
            // App settings live in Redis too, so the transaction doesn't undo them, and set(null)
            // is a no-op: an unset flag has to be deleted or it leaks into every later test.
            $originalFlag === null
                ? $this->testApp->del(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)
                : $this->testApp->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, $originalFlag);
        }
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

/**
 * A tool that fails: with an internal error, or with a validation message written for the model.
 */
final class CrashingVoiceTool extends NeuronTool
{
    public function __construct()
    {
        $this->name = 'crashing_voice_tool';
        $this->description = 'Test tool that throws.';
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'kind',
                type: PropertyType::STRING,
                description: 'crash or validation',
                required: true,
            ),
        ];
    }

    public function __invoke(string $kind = 'crash'): array
    {
        if ($kind === 'validation') {
            throw new ValidationException('That date is in the past.');
        }

        throw new RuntimeException('SQLSTATE[42S22]: Column not found: 1054 Unknown column');
    }
}
