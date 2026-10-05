<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\ChatHistory;

use Kanvas\Intelligence\Agents\ChatHistory\KanvasChatHistory;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Neuron\Stores\KanvasMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Override;
use Tests\TestCase;

/**
 * The stock history archives `count(before) − count(after)` oldest rows; a fold shortens the list
 * without dropping a turn, so the Kanvas history archives the ids a trim actually dropped.
 */
class KanvasChatHistoryTest extends TestCase
{
    public function testATrimArchivesExactlyTheDroppedIds(): void
    {
        $store = $this->store([
            new UserMessage(str_repeat('a', 400))->setId('u1'),
            new AssistantMessage(str_repeat('b', 400))->setId('a1'),
            new UserMessage(str_repeat('c', 400))->setId('u2'),
            new AssistantMessage(str_repeat('d', 400))->setId('a2'),
        ]);
        $history = $this->history($store, contextWindow: 260);

        $history->addMessage(new UserMessage('next')->setId('u3'));

        $this->assertSame(['u1', 'a1'], $store->archived);
        $this->assertSame(['u2', 'a2', 'u3'], array_map(fn (Message $m): string => $m->getId(), $history->getMessages()));
    }

    public function testAFoldedTurnIsNotArchived(): void
    {
        $store = $this->store([
            new UserMessage('hello')->setId('u1'),
            new AssistantMessage('first part')->setId('a1'),
        ]);
        $history = $this->history($store, contextWindow: 50_000);

        $history->addMessage(new AssistantMessage('second part')->setId('a2'));

        $this->assertSame([], $store->archived);
        $this->assertSame(['a2'], $store->persisted, 'The absorbed turn still gets its own row');
        $this->assertCount(2, $history->getMessages());
        $this->assertSame("first part\n\nsecond part", (string) $history->getMessages()[1]->getContent());
        $this->assertSame(['a2'], $history->getMessages()[1]->getMetadata(KanvasHistoryTrimmer::FOLDED_IDS));
    }

    /**
     * KANVAS-ECOSYSTEM-6JD: a wake prompt the provider refused stays as an unanswered user row, and the
     * next wake folds onto it. The live turn is never cut, so each failure made the next request bigger.
     */
    public function testAnUnansweredTurnTooBigToFoldIsReplacedAndArchived(): void
    {
        $store = $this->store([
            new UserMessage(str_repeat('old wake ', 2_000))->setId('u1'),
        ]);
        $history = $this->history($store, contextWindow: 2_000);

        $history->addMessage(new UserMessage(str_repeat('new wake ', 2_000))->setId('u2'));

        $this->assertSame(['u1'], $store->archived, 'The orphan row leaves the active window');
        $this->assertSame(['u2'], array_map(fn (Message $m): string => $m->getId(), $history->getMessages()));
        $this->assertStringStartsWith('new wake', (string) $history->getMessages()[0]->getContent());
    }

    public function testTwoShortUnansweredTurnsStillFoldIntoOne(): void
    {
        $store = $this->store([new UserMessage('first question')->setId('u1')]);
        $history = $this->history($store, contextWindow: 2_000);

        $history->addMessage(new UserMessage('second question')->setId('u2'));

        $this->assertSame([], $store->archived);
        $this->assertSame("first question\n\nsecond question", (string) $history->getMessages()[0]->getContent());
    }

    public function testClearForgetsTheDedupeSetSoAKeptTailCanBeReappended(): void
    {
        $store = $this->store([]);
        $history = $this->history($store, contextWindow: 50_000);

        $history->addMessage(new UserMessage('hello')->setId('u1'));
        $history->flushAll();
        $history->addMessage(new UserMessage('hello')->setId('u1'));

        $this->assertSame(['u1', 'u1'], $store->persisted);
        $this->assertSame(1, $store->cleared);
    }

    /**
     * @param list<Message> $loaded
     */
    private function store(array $loaded): KanvasMessageStore
    {
        return new class ($loaded) extends KanvasMessageStore {
            /** @var list<string> */
            public array $archived = [];

            /** @var list<string> */
            public array $persisted = [];

            public int $cleared = 0;

            /**
             * @param list<Message> $loaded
             */
            public function __construct(private readonly array $loaded)
            {
            }

            #[Override]
            public function loadActive(string $threadId): array
            {
                return $this->loaded;
            }

            #[Override]
            public function archiveMessages(string $threadId, array $ids): void
            {
                $this->archived = [...$this->archived, ...$ids];
            }

            #[Override]
            protected function archiveAll(string $threadId): void
            {
                $this->cleared++;
            }

            #[Override]
            protected function persist(string $threadId, Message $message): void
            {
                $this->persisted[] = $message->getId();
            }
        };
    }

    private function history(KanvasMessageStore $store, int $contextWindow): KanvasChatHistory
    {
        return new KanvasChatHistory(
            $store,
            'thread',
            $contextWindow,
            KanvasHistoryTrimmer::make(),
        );
    }
}
