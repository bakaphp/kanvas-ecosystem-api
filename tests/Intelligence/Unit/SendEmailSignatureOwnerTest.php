<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendEmailTool;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\Users\Models\Users;
use Mockery;
use Tests\TestCaseUnit;

final class SendEmailSignatureOwnerTest extends TestCaseUnit
{
    public function testCustomerAndInternalAgentSignatureOwnershipWithoutAiAssist(): void
    {
        $bot = new Users();
        $human = new Users();
        $tool = new class () extends SendEmailTool {
            public function owner(): ?Users
            {
                return $this->signatureOwner();
            }
        };
        $internal = Mockery::mock(Agent::class);
        $internal->shouldReceive('conversesWithUser')->andReturn(true);
        $external = Mockery::mock(Agent::class);
        $external->shouldReceive('conversesWithUser')->andReturn(false);

        $tool->withContext(
            new Apps(),
            new Companies(),
            $bot,
            $internal,
        );
        $this->assertNull($tool->owner());
        $tool->forConversationHuman($human);
        $this->assertSame($human, $tool->owner());
        $tool->withContext(
            new Apps(),
            new Companies(),
            $bot,
            $external,
        );
        $this->assertNull($tool->owner());
        $tool->withContext(new Apps(), new Companies(), $bot);
        $this->assertNull($tool->owner());
    }

    public function testKernelPassesTrustedAiAssistSurfaceToSalesHandler(): void
    {
        $human = new Users();
        $app = new Apps();
        $agent = Mockery::mock(Agent::class);
        $agent->shouldReceive('isContainerRuntime')->andReturn(false);
        $agent->shouldReceive('getAttribute')->with('type')->andReturn((object) ['handler' => SignatureKernelSalesAgent::class]);
        $agent->shouldReceive('getAttribute')->with('app')->andReturn($app);
        $kernel = new class ($agent, null, 'Send email like me', $human, humanDirectedConversation: true) extends AgentChatKernel {
            public function runForTest(): string
            {
                return $this->runHandler();
            }
        };
        try {
            $kernel->runForTest();
            $this->fail('The test handler must stop before any model execution.');
        } catch (SignatureKernelBoundException $e) {
            $this->assertTrue($e->handler->isHumanDirectedConversation());
            $this->assertSame($human, $e->handler->requestingHuman());
        }
    }

    public function testAiAssistUsesHumanSignatureWithTheRealSalesAgentHandler(): void
    {
        $human = new Users();
        $bot = new Users();
        $agent = new Agent();
        $agent->setRelation('type', (object) ['handler' => SalesAgent::class]);
        $tool = new class () extends SendEmailTool {
            public function owner(): ?Users
            {
                return $this->signatureOwner();
            }
        };
        $tool->withContext(new Apps(), new Companies(), $bot, $agent);
        $tool->forConversationHuman($human, humanDirected: true);
        $this->assertSame($human, $tool->owner());

        // A subsequent customer turn must not inherit the internal surface or its sender.
        $tool->forConversationHuman($bot);
        $this->assertNull($tool->owner());
        $tool->forConversationHuman(null, humanDirected: true);
        $this->assertNull($tool->owner());
    }

    public function testRealSalesAgentPassesAiAssistSurfaceThroughTheToolRegistry(): void
    {
        $human = new Users();
        $agent = new Agent();
        $agent->setRelation('type', (object) ['handler' => SalesAgent::class]);
        $host = new class () extends SalesAgent {
            public function wire(object $tool): object
            {
                return $this->mergeRegisteredTools([$tool], null, \Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum::NEURON)[0];
            }

            public function toolDependencyCandidates(): array
            {
                return [];
            }
        };
        $host->setConversationHuman($human);
        $host->setHumanDirectedConversation(true);
        $tool = new class () extends SendEmailTool {
            public function owner(): ?Users
            {
                return $this->signatureOwner();
            }
        };
        $tool->withContext(new Apps(), new Companies(), new Users(), $agent);
        $host->wire($tool);
        $this->assertSame($human, $tool->owner());
        $host->setHumanDirectedConversation(false);
        $host->wire($tool);
        $this->assertNull($tool->owner());
    }

    public function testRegistryWiresRequestingHumanRatherThanBotContext(): void
    {
        $human = new Users();
        $host = new class ($human) {
            use MergesRegisteredTools;

            public function __construct(private Users $human)
            {
            }

            public function requestingHuman(): Users
            {
                return $this->human;
            }

            public function wire(object $tool): object
            {
                return $this->fillKanvasContext($tool);
            }
        };
        $agent = Mockery::mock(Agent::class);
        $agent->shouldReceive('conversesWithUser')->andReturn(true);
        $tool = new class () extends SendEmailTool {
            public function owner(): ?Users
            {
                return $this->signatureOwner();
            }
        };
        $tool->withContext(
            new Apps(),
            new Companies(),
            new Users(),
            $agent,
        );
        $host->wire($tool);
        $this->assertSame($human, $tool->owner());
    }
}

/** Avoid database-backed tenant resolution; exercise the real handler's caller/surface state. */
class SignatureKernelSalesAgent extends SalesAgent
{
    public function setConfiguration(Agent $agent, ?Model $entity = null, ?Users $user = null): void
    {
        $this->user = $user;
    }

    public function setRendersArtifacts(bool $renders): void
    {
        throw new SignatureKernelBoundException($this);
    }
}

class SignatureKernelBoundException extends \RuntimeException
{
    public function __construct(public SignatureKernelSalesAgent $handler)
    {
        parent::__construct('Stop before model execution');
    }
}
