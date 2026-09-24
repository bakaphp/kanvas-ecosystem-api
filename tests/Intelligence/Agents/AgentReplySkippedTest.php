<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Exceptions\AgentReplySkippedException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Contracts\SilentWorkflowException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

final class AgentReplySkippedTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence', 'crm'];

    /**
     * The marker is what makes executeIntegration skip report() — if this ever stops being a
     * SilentWorkflowException the 240-event "Ai Agent Off" noise comes back (KANVAS-ECOSYSTEM-5E9).
     */
    public function testSkipExceptionIsSilentWorkflowException(): void
    {
        $this->assertInstanceOf(
            SilentWorkflowException::class,
            new AgentReplySkippedException('Ai Agent Off for this lead')
        );
    }

    /**
     * An agent that returns nothing is a business outcome, not a fault — it must be flagged FAILED
     * in the integration history without reaching Sentry (KANVAS-ECOSYSTEM-5T1, 64 events).
     */
    public function testEmptyReplyThrowsSilentSkip(): void
    {
        $action = new ReflectionClass(AgentChannelResponderAction::class)->newInstanceWithoutConstructor();
        $createMessage = new ReflectionMethod($action, 'createMessage');

        $this->expectException(AgentReplySkippedException::class);
        $this->expectExceptionMessage('Empty message was created');

        $createMessage->invoke(
            $action,
            '',
            '+13123884288',
            new Message(),
            new Channel()
        );
    }

    /**
     * SilentWorkflowException is only honoured by KanvasActivity::executeIntegration, so it does
     * nothing for a skip raised from a queued job or a GraphQL mutation. ShouldntReport is what
     * keeps those lanes out of Sentry — assert against the handler itself, not just the interface,
     * because $dontReport and the reportable() callback both live there.
     */
    public function testSkipExceptionIsNeverReportedToSentry(): void
    {
        $exception = new AgentReplySkippedException('Agent 1 is deactivated');

        $this->assertInstanceOf(ShouldntReport::class, $exception);

        $handler = app(ExceptionHandler::class);
        $shouldntReport = new ReflectionMethod($handler, 'shouldntReport');

        $this->assertTrue(
            $shouldntReport->invoke($handler, $exception),
            'A deactivated-agent skip must not reach the reporter.'
        );
    }

    /**
     * The whole point of deactivating: inbound still arrives, but no backend is ever reached, so no
     * tokens are spent. The guard also has to sit outside execute()'s own try/catch — if it drifts
     * inside, AgentProviderException::fromThrowable rewraps it and this expectation fails.
     */
    public function testDeactivatedAgentSkipsBeforeReachingAnyBackend(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'is_active' => 0,
            ]);

        $this->expectException(AgentReplySkippedException::class);
        $this->expectExceptionMessage(sprintf('Agent %d is deactivated', $agent->getId()));

        new AgentChatKernel(
            agent: $agent,
            session: null,
            message: 'this must never reach a model',
            user: $user,
        )->execute();
    }

    public function testActiveAgentPassesTheDeactivationGuard(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'is_active' => 1,
            ]);

        try {
            new AgentChatKernel(
                agent: $agent,
                session: null,
                message: 'hello',
                user: $user,
            )->execute();
        } catch (AgentReplySkippedException $e) {
            $this->fail('An active agent must not be skipped: ' . $e->getMessage());
        } catch (Throwable) {
            // Anything else means the guard let the turn through and a backend took over, which is
            // all this asserts — actually running a model is not this test's job.
        }

        $this->assertTrue($agent->is_active);
    }

    public function testConstructorThrowsSkipWhenLeadAiModeIsOff(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()->withAppAndCompany($app->getId(), $company->getId())->create();
        $lead->set('ai_mode', 'OFF');

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'whatsapp'],
            ['name' => 'WhatsApp']
        );

        $channel = Channel::firstOrCreate(
            ['apps_id' => $app->getId(), 'companies_id' => $company->getId(), 'slug' => 'wa-skip-test'],
            ['name' => 'WA Skip', 'description' => 'Test', 'users_id' => $user->getId()]
        );

        $inbound = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'hi', 'from_me' => false],
                'is_locked' => 0,
                'is_un_response' => 0,
            ]);

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $inbound->getId(),
            'message_types_id' => $messageType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inbound = $inbound->fresh();

        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        $this->expectException(AgentReplySkippedException::class);

        new AgentChannelResponderAction($channel, $inbound, $agent, null);
    }
}
