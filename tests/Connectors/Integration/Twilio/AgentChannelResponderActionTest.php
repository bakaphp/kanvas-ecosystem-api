<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\AgentChannelResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Override;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

/**
 * What the agent is actually asked. The inbound burst closed before this runs, so the turn's prompt
 * is the whole flurry — answering only the head is how a customer who sent two texts got an answer
 * to the first one and a second reply for the rest.
 */
class AgentChannelResponderActionTest extends TestCase
{
    use DatabaseTransactions;

    public function testAnswersTheWholeBurstWhenBurstTextIsSupplied(): void
    {
        ['message' => $message, 'channel' => $channel, 'agent' => $agent] = $this->setupLeadMessageChannelAgent();

        $burst = "I am observing the Sabbath.\n\n(I am not receiving notifications.)";

        $result = $this->respond(
            $channel,
            $message,
            $agent,
            ['from' => '+19876543210', 'burst_text' => $burst]
        );

        $this->assertSame($burst, $result['message']);
    }

    public function testFallsBackToTheMessageContentWhenThereIsNoBurst(): void
    {
        ['message' => $message, 'channel' => $channel, 'agent' => $agent] = $this->setupLeadMessageChannelAgent();

        $result = $this->respond(
            $channel,
            $message,
            $agent,
            ['from' => '+19876543210']
        );

        $this->assertSame('test sms message', $result['message']);
    }

    /**
     * A burst of nothing but media has no text to speak of; the head's own body is still the better
     * prompt than an empty string, which the agent would answer as if nobody had said anything.
     */
    public function testBlankBurstTextFallsBackToTheMessageContent(): void
    {
        ['message' => $message, 'channel' => $channel, 'agent' => $agent] = $this->setupLeadMessageChannelAgent();

        $result = $this->respond(
            $channel,
            $message,
            $agent,
            ['from' => '+19876543210', 'burst_text' => "  \n "]
        );

        $this->assertSame('test sms message', $result['message']);
    }

    private function respond(
        Channel $channel,
        Message $message,
        Agent $agent,
        array $params
    ): array {
        Http::fake();

        // The outbound Twilio call is the only part that needs real credentials, and it is not what
        // these assertions are about.
        $action = new class ($channel, $message, $agent) extends AgentChannelResponderAction {
            #[Override]
            protected function dispatchMessage(string $to, string $from, string $body): void
            {
            }
        };

        return $action->execute($params);
    }

    private function setupLeadMessageChannelAgent(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $user->getId());

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads']
        );

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'test sms message', 'from_me' => false],
                'is_locked' => 0,
                'is_un_response' => 0,
            ]);

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $message->getId(),
            'message_types_id' => $messageType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message = $message->fresh();

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'twilio-1' . fake()->unique()->numerify('##########'),
            ],
            [
                'name' => 'Test Twilio Channel',
                'description' => 'Test channel for burst prompt assembly',
                'users_id' => $user->getId(),
            ]
        );

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);

        return compact('lead', 'message', 'channel', 'agent');
    }
}
