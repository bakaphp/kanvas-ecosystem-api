<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Tests\Stubs\Intelligence\FakeCrmOnlyToolHandler;
use Tests\TestCase;
use Tests\Traits\ForcesStoredHandler;

/**
 * Class names stored in agent types, tools, approval policies and receiver actions are built at run
 * time, so each column only accepts a class implementing the contract its runtime expects.
 */
final class StoredHandlerClassesTest extends TestCase
{
    use ForcesStoredHandler;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence', 'workflow'];

    public function testAnAgentTypeHandlerMustBeAnAgentHandler(): void
    {
        $this->expectException(ValidationException::class);

        AgentType::factory()->create(['handler' => Users::class]);
    }

    public function testAHandlerWrittenByHandIsNotBuiltUnlessItIsAnAgentHandler(): void
    {
        $agentType = AgentType::factory()->create(['handler' => KanvasGenericNeuronAgent::class]);
        $this->assertSame(KanvasGenericNeuronAgent::class, $agentType->handlerClass());

        $this->assertNull($this->forceStoredHandler($agentType, Users::class)->handlerClass());
    }

    public function testAToolHandlerMustBeATool(): void
    {
        $this->assertSame(FakeCrmOnlyToolHandler::class, $this->createTool(FakeCrmOnlyToolHandler::class)->handlerClass());

        $this->expectException(ValidationException::class);

        $this->createTool(Users::class);
    }

    public function testAnApprovalHandlerMustBeAnApprovalHandler(): void
    {
        $this->expectException(ValidationException::class);

        ApprovalPolicy::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'system_modules_id' => 1,
            'approval_type' => 'stored-handler-test',
            'handler' => Users::class,
            'steps' => [],
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);
    }

    public function testAReceiverCanOnlyRunAWebhookJob(): void
    {
        $action = WorkflowAction::factory()->create([
            'name' => 'not-a-webhook-job-' . uniqid(),
            'model_name' => Users::class,
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation createReceiverWebhook($input: ReceiverWebhookInput!) {
                createReceiverWebhook(input: $input) { id }
            }',
            ['input' => ['action_id' => $action->getId(), 'name' => 'receiver-' . uniqid()]]
        );

        $this->assertStringContainsString('is not an allowed receiver action', (string) $response->json('errors.0.message'));
    }

    private function createTool(string $handler)
    {
        return new CreateToolAction(
            new ToolData(
                app: app(Apps::class),
                name: 'stored-handler-' . uniqid(),
                frameworks: ['neuron'],
                toolType: ToolTypeEnum::CUSTOM,
                handler: $handler,
            ),
        )->execute();
    }
}
