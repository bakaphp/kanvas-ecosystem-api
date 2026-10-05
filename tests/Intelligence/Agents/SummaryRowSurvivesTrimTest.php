<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\ChatHistory\KanvasHistoryTrimmer;
use Kanvas\Intelligence\Agents\Neuron\Middleware\KanvasSummarization;
use Kanvas\Intelligence\Agents\Neuron\Stores\ConversationMessageStore;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * The hard cut drops the oldest rows, and the summary is the oldest row by design. Dropping it would
 * throw away the compressed past to make room for the present, so it rides on top of the cut instead.
 */
class SummaryRowSurvivesTrimTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testACutThatWouldDropTheSummaryKeepsItAhead(): void
    {
        $summary = new UserMessage("## Previous conversation summary:\n\nAcme wants quarterly invoicing; Ana signs on Fridays.");
        $summary->addMetadata(KanvasSummarization::SUMMARY_FLAG, true);

        $messages = [$summary];
        for ($turn = 1; $turn <= 6; $turn++) {
            $messages[] = new AssistantMessage(str_repeat("Reply {$turn} with plenty of detail. ", 40));
            $messages[] = new UserMessage("Question {$turn}: " . str_repeat('more context ', 30));
        }

        $trimmer = KanvasHistoryTrimmer::make();
        $kept = $trimmer->trim($messages, 500);

        $this->assertLessThan(count($messages), count($kept), 'The window is small enough that a cut happened');
        $this->assertStringStartsWith('## Previous conversation summary:', (string) $kept[0]->getContent());
        $this->assertStringContainsString('Question 6', (string) end($kept)->getContent(), 'The newest turn is still the newest');
        $fresh = KanvasHistoryTrimmer::make();
        $fresh->trim(array_map(static fn ($message) => clone $message, $kept), PHP_INT_MAX);
        $this->assertEqualsWithDelta($fresh->getTotalTokens(), $trimmer->getTotalTokens(), 10, 'The summary is counted on top of the cut');
    }

    public function testAHistoryThatFitsIsLeftAlone(): void
    {
        $summary = new UserMessage("## Previous conversation summary:\n\nShort.");
        $summary->addMetadata(KanvasSummarization::SUMMARY_FLAG, true);
        $messages = [$summary, new AssistantMessage('Noted.'), new UserMessage('Next question?')];

        $this->assertSame($messages, KanvasHistoryTrimmer::make()->trim($messages, 50_000));
    }

    public function testTheFlagComesBackWhenTheRowIsReloaded(): void
    {
        $user = auth()->user();
        $agent = $this->makeAgentFor($user);
        $thread = (string) Str::uuid();
        $store = new ConversationMessageStore(
            app: app(Apps::class),
            company: $agent->company,
            user: $agent->user,
            agentClass: SystemUserAgent::class,
            sessionId: $thread,
            agent: $agent,
        );
        $summary = new UserMessage("## Previous conversation summary:\n\nReloaded.");
        $summary->addMetadata(KanvasSummarization::SUMMARY_FLAG, true);

        $store->append($thread, $summary);
        $store->append($thread, new AssistantMessage('Still here.'));

        $loaded = $store->loadActive($thread);

        $this->assertTrue($loaded[0]->getMetadata(KanvasSummarization::SUMMARY_FLAG), 'Without the flag the next turn\'s trim cannot tell the summary from any other row');
        $this->assertNull($loaded[1]->getMetadata(KanvasSummarization::SUMMARY_FLAG));
    }
}
