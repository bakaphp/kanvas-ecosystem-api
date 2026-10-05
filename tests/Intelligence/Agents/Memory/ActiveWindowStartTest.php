<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Stores\ConversationMessageStore;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

class ActiveWindowStartTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testTheWindowStartsAtTheOldestRowATrimOrSummaryLeftActive(): void
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

        $this->assertNull($store->activeWindowStartedAt($thread), 'Nothing written yet');

        Carbon::setTestNow('2026-10-04 10:00:00');
        $old = new UserMessage('first question');
        $store->append($thread, $old);
        $store->append($thread, new AssistantMessage('first answer'));

        Carbon::setTestNow('2026-10-04 10:05:00');
        $store->append($thread, new UserMessage('second question'));
        Carbon::setTestNow();

        $this->assertSame(Carbon::parse('2026-10-04 10:00:00')->getTimestamp(), $store->activeWindowStartedAt($thread));

        $store->archiveMessages($thread, [ConversationMessageStore::bareId($old->getId())]);

        $this->assertSame(
            Carbon::parse('2026-10-04 10:00:00')->getTimestamp(),
            $store->activeWindowStartedAt($thread),
            'The first answer, written in the same second, is still active'
        );
    }
}
