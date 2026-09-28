<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SummarizeLeadConversationAction;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Jobs\SummarizeLeadConversationJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadConversationSummaryRepository;
use Kanvas\Guild\Leads\Services\LeadConversationTranscriptService;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\PastOpportunitiesTool;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

final class LeadConversationSummaryTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'intelligence', 'social'];

    private const int CLOSED_STATUS_ID = 3;

    public function testSummaryIsStoredAsSummaryMessageOnTheLead(): void
    {
        $lead = $this->makeLead($this->makePeople());
        $this->addMessage($lead, 'I want a 2022 Tacoma under 35k');
        $this->addMessage($lead, 'We have one in stock, want to see it?', fromAgent: true);

        $summary = $this->summarize($lead, '- Wanted a 2022 Tacoma under $35k');

        $this->assertNotNull($summary);
        $this->assertSame(LeadMessageTypeEnum::CONVERSATION_SUMMARY->value, $summary->messageType->verb);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('2022 Tacoma under 35k'));

        $latest = LeadConversationSummaryRepository::latestForLeads($lead->app, [$lead->getId()]);
        $this->assertSame($summary->getId(), $latest[$lead->getId()]->getId());
    }

    public function testSummaryIsNotRewrittenUntilTheConversationContinues(): void
    {
        $lead = $this->makeLead($this->makePeople());
        $this->addMessage($lead, 'Looking for a trade-in quote');

        $this->summarize($lead, '- Trade-in quote');
        $this->assertTrue(LeadConversationSummaryRepository::hasUpToDateSummary($lead));

        $this->assertNull($this->summarize($lead, '- again'));

        $this->addMessage($lead, 'Actually I came back, still interested');

        $this->assertFalse(LeadConversationSummaryRepository::hasUpToDateSummary($lead));
    }

    public function testSummaryNeverReachesTheAgentConversationHistory(): void
    {
        $lead = $this->makeLead($this->makePeople());
        $this->addMessage($lead, 'Hello there');

        $this->summarize($lead, '- SUMMARY MARKER');

        $transcript = implode("\n", LeadConversationTranscriptService::lines($lead));

        $this->assertStringContainsString('Hello there', $transcript);
        $this->assertStringNotContainsString('SUMMARY MARKER', $transcript);
    }

    public function testLeadWithoutConversationIsNotSummarized(): void
    {
        $lead = $this->makeLead($this->makePeople());

        $this->assertNull($this->summarize($lead, '- unused'));
        AnonymousAgent::assertNeverPrompted();
    }

    public function testClosingALeadOfAnAiEnabledCompanyQueuesTheSummary(): void
    {
        Bus::fake([SummarizeLeadConversationJob::class]);

        $lead = $this->makeLead($this->makePeople($this->aiEnabledCompany()));

        $lead->leads_status_id = self::CLOSED_STATUS_ID;
        $lead->saveOrFail();

        Bus::assertDispatched(
            SummarizeLeadConversationJob::class,
            fn (SummarizeLeadConversationJob $job): bool => $job->lead->is($lead)
        );
    }

    public function testClosingALeadWithAiDisabledQueuesNothing(): void
    {
        Bus::fake([SummarizeLeadConversationJob::class]);

        $lead = $this->makeLead($this->makePeople(Companies::factory()->create()));

        $lead->leads_status_id = self::CLOSED_STATUS_ID;
        $lead->saveOrFail();

        Bus::assertNotDispatched(SummarizeLeadConversationJob::class);
    }

    public function testPastOpportunitiesReturnsStoredSummary(): void
    {
        Bus::fake([SummarizeLeadConversationJob::class]);

        $people = $this->makePeople();
        $pastLead = $this->makeLead($people);
        $this->addMessage($pastLead, 'Bought the Tacoma, thanks');
        $this->summarize($pastLead, '- Bought the Tacoma');

        $result = $this->pastOpportunitiesFor($this->makeLead($people));

        $this->assertSame('- Bought the Tacoma', $result['past_opportunities'][0]['conversation_summary']);
        $this->assertArrayNotHasKey('recent_messages', $result['past_opportunities'][0]);
        Bus::assertNotDispatched(SummarizeLeadConversationJob::class);
    }

    public function testPastOpportunitiesWithoutSummaryReturnsRecentMessagesAndQueuesOne(): void
    {
        Bus::fake([SummarizeLeadConversationJob::class]);

        $people = $this->makePeople($this->aiEnabledCompany());
        $pastLead = $this->makeLead($people);
        // Alternating sides: the history loader merges consecutive same-role messages into one turn.
        foreach (['one', 'two', 'three', 'four'] as $i => $text) {
            $this->addMessage($pastLead, "message {$text}", fromAgent: $i % 2 === 1);
        }
        $pastLead->leads_status_id = self::CLOSED_STATUS_ID;
        $pastLead->saveQuietly();

        $result = $this->pastOpportunitiesFor($this->makeLead($people));
        $opportunity = $result['past_opportunities'][0];

        $this->assertNull($opportunity['conversation_summary']);
        $this->assertCount(3, $opportunity['recent_messages']);
        $this->assertStringContainsString('message four', end($opportunity['recent_messages']));
        Bus::assertDispatched(SummarizeLeadConversationJob::class);
    }

    private function summarize(Lead $lead, string $response): ?Message
    {
        AnonymousAgent::fake([$response]);

        return new SummarizeLeadConversationAction($lead)->execute();
    }

    private function pastOpportunitiesFor(Lead $lead): array
    {
        return new PastOpportunitiesTool()
            ->withContext(app(Apps::class), $lead->company, $this->user())
            ->__invoke(lead_id: $lead->getId());
    }

    private function addMessage(Lead $lead, string $content, bool $fromAgent = false): void
    {
        $message = Message::factory()
            ->withAppId($lead->apps_id)
            ->withCompanyId($lead->companies_id)
            ->withMessageType(MessageTypeService::getOrCreate($lead->app, $fromAgent ? 'agent' : 'user'))
            ->create([
                'message' => [
                    'content' => $content,
                    'from_ia' => $fromAgent,
                ],
            ]);

        $message->addEntity($lead);
    }

    /**
     * A fresh company, not the shared test one: the setting lives in Redis, which no transaction rolls back.
     */
    private function aiEnabledCompany(): Companies
    {
        $company = Companies::factory()->create();
        $company->set(ConfigurationEnum::AI_ENABLE->value, 1);

        return $company;
    }

    private function makePeople(?Companies $company = null): People
    {
        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(($company ?? $this->user()->getCurrentCompany())->getId())
            ->withUserId($this->user()->getId())
            ->create();
    }

    private function makeLead(People $people): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($people->companies_id)
            ->withPeopleId($people->getId())
            ->create(['leads_status_id' => 1]);
    }

    private function user(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
