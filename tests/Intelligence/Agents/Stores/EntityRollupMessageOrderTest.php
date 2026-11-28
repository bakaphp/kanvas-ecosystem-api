<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Stores;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasChatHistory;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Neuron\Stores\EntityRollupMessageStore;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\MakesLeadConversations;

final class EntityRollupMessageOrderTest extends TestCase
{
    use DatabaseTransactions;
    use MakesLeadConversations;

    protected $connectionsToTransact = [null, 'crm', 'social', 'workflow', 'intelligence'];

    public static function entities(): array
    {
        return ['person' => [false], 'lead' => [true]];
    }

    #[DataProvider('entities')]
    public function testBackfillingOlderMessagesPreservesChronology(bool $onLead): void
    {
        Queue::fake();
        $lead = $this->makeLead();
        $entity = $onLead ? $lead : $lead->people;
        $older = $this->leadMessage($lead, 'I can come on Friday.', attach: false);
        $reply = $this->leadMessage($lead, 'Friday is available.', fromAgent: true, attach: false);
        $newer = $this->leadMessage($lead, 'Change that to Saturday.', attach: false);
        $newer->addEntity($entity);
        $older->addEntity($entity);
        $reply->addEntity($entity);

        $store = new EntityRollupMessageStore(
            app: $lead->app,
            company: $lead->company,
            user: auth()->user(),
            entity: $entity,
        );

        $this->assertSame(
            ['social:' . $older->getId(), 'social:' . $reply->getId(), 'social:' . $newer->getId()],
            array_map(fn (Message $turn): string => $turn->getId(), $store->loadActive('thread')),
        );

        $history = new KanvasChatHistory(
            $store,
            'thread',
            50_000,
            KanvasHistoryTrimmer::make(),
        );
        $questions = array_values(array_filter($history->getMessages(), fn (Message $turn): bool => $turn->getRole() === 'user'));
        $this->assertStringContainsString('Change that to Saturday.', (string) end($questions)->getContent());
    }

    public function testAPersonHistoryLabelsTheLeadOnInboundTurnsOnly(): void
    {
        Queue::fake();
        $lead = $this->makeLead();
        $label = '[Lead: ' . $lead->title . ']';
        $this->leadMessage($lead, 'Yes')->addEntity($lead->people);
        $this->leadMessage($lead, 'Friday is available.', fromAgent: true)->addEntity($lead->people);
        $this->leadMessage($lead, $label . " Hi Marie.\n\n" . $label . ' Sent.', fromAgent: true)
            ->addEntity($lead->people);

        $turns = new EntityRollupMessageStore(
            app: $lead->app,
            company: $lead->company,
            user: auth()->user(),
            entity: $lead->people,
        )->loadActive('thread');

        $this->assertStringStartsWith($label, (string) $turns[0]->getContent());
        $this->assertSame('Friday is available.', (string) $turns[1]->getContent());
        $this->assertSame("Hi Marie.\n\nSent.", (string) $turns[2]->getContent());
    }
}
