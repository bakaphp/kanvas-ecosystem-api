<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
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

final class AgentReplySkippedTest extends TestCase
{
    use DatabaseTransactions;

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
