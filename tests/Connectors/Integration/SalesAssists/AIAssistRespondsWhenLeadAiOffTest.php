<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\AIAssistAgentResponderAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Actions\BaseAgentChannelReplyAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class AIAssistRespondsWhenLeadAiOffTest extends TestCase
{
    public function testAIAssistBuildsEvenWhenLeadHasAiOff(): void
    {
        [$channel, $message, $agent] = $this->scaffold(IntelligenceModeEnum::IDLE);

        // The AI Assist channel is internal (lead-owner talking TO the AI). The lead's
        // ai_mode=IDLE only silences customer-facing auto-replies, so construction must NOT throw.
        $action = new AIAssistAgentResponderAction($channel, $message, $agent);

        $this->assertInstanceOf(AIAssistAgentResponderAction::class, $action);
    }

    public function testCustomerFacingResponderStillBlocksWhenLeadHasAiOff(): void
    {
        [$channel, $message, $agent] = $this->scaffold(IntelligenceModeEnum::IDLE);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Ai Agent Off for this lead');

        new BaseAgentChannelReplyAction($channel, $message, $agent);
    }

    private function scaffold(IntelligenceModeEnum $mode): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        $lead->set('ai_mode', $mode->value);

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'ai-assist'],
            ['name' => 'AI Assist']
        );

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'slug' => 'ai-assist-test-' . $lead->getId(),
            ],
            ['name' => 'AI Assist Test', 'description' => 'Test channel', 'users_id' => $user->getId()]
        );

        $message = Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'What is this lead interested in?', 'from_human' => true],
                'is_locked' => 0,
                'is_un_response' => 0,
            ]);
        $message->addEntity($lead);
        $message = $message->fresh();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron']);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        return [$channel, $message, $agent];
    }
}
