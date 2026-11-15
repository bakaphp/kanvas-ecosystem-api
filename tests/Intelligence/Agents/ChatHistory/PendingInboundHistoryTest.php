<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\ChatHistory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\Stubs\Intelligence\LeadSummaryCustomerAgentStub;
use Tests\Stubs\Intelligence\LeadSummaryStubProviders;
use Tests\TestCase;
use Tests\Traits\MakesAgents;
use Tests\Traits\MakesLeadConversations;

final class PendingInboundHistoryTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;
    use MakesLeadConversations;

    protected $connectionsToTransact = [null, 'crm', 'social', 'workflow', 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        LeadSummaryStubProviders::reset();
        LeadSummaryStubProviders::$window = 50_000;
    }

    public static function entities(): array
    {
        return ['person' => [false], 'lead' => [true]];
    }

    #[DataProvider('entities')]
    public function testTheFirstRequestIncludesTheCurrentInboundOnce(bool $onLead): void
    {
        $lead = $this->makeLead();
        $message = $this->leadMessage($lead, 'Reserve the blue car for Friday.');
        $message->addEntity($lead->people);
        $handler = $this->handler($onLead ? $lead : $lead->people, $message);

        $handler->chat(new UserMessage($message->contentText()));

        $this->assertSame(1, substr_count($this->sentText(), $message->contentText()));
        $this->assertStringNotContainsString($message->contentText(), (string) LeadSummaryStubProviders::$chats->instructions);
    }

    public function testOnlyTheExactBurstIsExcludedAndAnOlderIdenticalQuestionSurvives(): void
    {
        $lead = $this->makeLead();
        $this->leadMessage($lead, 'Reserve the blue car.');
        $this->leadMessage($lead, 'Which day?', true);
        $head = $this->leadMessage($lead, 'Reserve the blue car.');
        $part = $this->leadMessage($lead, 'On Friday, please.');
        $this->leadMessage($lead, 'A separate incoming message.');
        $handler = $this->handler($lead, $head, [$head->getId(), $part->getId()]);

        $handler->chat(new UserMessage($head->contentText() . "\n\n" . $part->contentText()));

        $this->assertSame(2, substr_count($this->sentText(), $head->contentText()), 'The earlier question is a different message');
        $this->assertSame(1, substr_count($this->sentText(), $part->contentText()));
        $this->assertSame(1, substr_count($this->sentText(), 'A separate incoming message.'));
    }

    public function testThePendingPromptKeepsItsNativeAttachment(): void
    {
        $lead = $this->makeLead();
        $message = $this->leadMessage($lead, 'Is this the blue car?');
        $prompt = new UserMessage($message->contentText());
        $prompt->addContent(new ImageContent('aGVsbG8=', SourceType::BASE64, 'image/png'));

        $this->handler($lead, $message)->chat($prompt);

        $this->assertSame(1, substr_count($this->sentText(), $message->contentText()));
        $images = array_filter(FakeNeuronProvider::$lastMessages, fn (NeuronMessage $turn): bool => $turn->getImage() !== null);
        $this->assertCount(1, $images);
    }

    public function testARetriedProviderRequestStillIncludesTheInboundOnce(): void
    {
        $lead = $this->makeLead();
        $message = $this->leadMessage($lead, 'Please confirm Friday.');
        LeadSummaryStubProviders::$chats = new class () extends FakeNeuronProvider {
            #[Override]
            public function chat(NeuronMessage ...$messages): ProviderResponse
            {
                parent::chat(...$messages);

                throw new RuntimeException('Provider unavailable');
            }
        };

        try {
            $this->handler($lead, $message)->chat(new UserMessage($message->contentText()));
            $this->fail('The provider must fail');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider unavailable', $exception->getMessage());
        }

        LeadSummaryStubProviders::$chats = new FakeNeuronProvider();
        $this->handler($lead, $message)->chat(new UserMessage($message->contentText()));

        $this->assertSame(1, substr_count($this->sentText(), $message->contentText()));
    }

    public function testTheNextTurnStillLoadsThePreviousExchange(): void
    {
        $lead = $this->makeLead();
        $first = $this->leadMessage($lead, 'Can you hold it for Friday?');
        $this->handler($lead, $first)->chat(new UserMessage($first->contentText()));
        $this->leadMessage($lead, 'Yes, Friday is confirmed.', true);
        $next = $this->leadMessage($lead, 'What time should I arrive?');

        $this->handler($lead, $next)->chat(new UserMessage($next->contentText()));

        $this->assertSame(1, substr_count($this->sentText(), $first->contentText()));
        $this->assertStringContainsString('Yes, Friday is confirmed.', $this->sentText());
        $this->assertSame(1, substr_count($this->sentText(), $next->contentText()));
    }

    public function testTheKernelForwardsTheExactBurstToTheHistory(): void
    {
        $lead = $this->makeLead();
        $head = $this->leadMessage($lead, 'Reserve this car.');
        $part = $this->leadMessage($lead, 'For Saturday.');
        $head->addEntity($lead->people);
        $part->addEntity($lead->people);
        $type = AgentType::factory()->withAppId($lead->apps_id)->create([
            'provider' => 'neuron',
            'handler' => LeadSummaryCustomerAgentStub::class,
        ]);
        $agent = $this->makeAgentFor(auth()->user(), $type);
        $session = Session::create([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agents_id' => $agent->getId(),
            'entity_namespace' => $lead->people::class,
            'entity_id' => $lead->people->getId(),
            'user' => [],
            'content' => [],
        ]);
        $kernel = new AgentChatKernel(
            agent: $agent,
            session: $session,
            message: $head->contentText() . "\n\n" . $part->contentText(),
            user: auth()->user(),
            sourceChannel: new Channel(),
            sourceMessage: $head,
            persistConversation: false,
            fallbackOnFailure: false,
            burstMessageIds: [$head->getId(), $part->getId()],
        );

        $this->assertSame('ok', new ReflectionMethod($kernel, 'runHandler')->invoke($kernel));
        $this->assertSame(1, substr_count($this->sentText(), $head->contentText()));
        $this->assertSame(1, substr_count($this->sentText(), $part->contentText()));
    }

    private function handler(Model $entity, Message $message, array $burstMessageIds = []): LeadSummaryCustomerAgentStub
    {
        $handler = new LeadSummaryCustomerAgentStub();
        $handler->setConfiguration(agent: $this->makeAgentFor(auth()->user()), entity: $entity, user: auth()->user());
        $handler->setThreadId((string) $entity->uuid);
        $handler->setInboundMessageId($message->getId(), $burstMessageIds);

        return $handler;
    }

    private function sentText(): string
    {
        return implode("\n", array_map(fn (NeuronMessage $turn): string => (string) $turn->getContent(), FakeNeuronProvider::$lastMessages));
    }
}
