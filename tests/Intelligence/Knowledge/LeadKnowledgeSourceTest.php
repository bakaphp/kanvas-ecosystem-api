<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Enums\AgentMessageTypeEnum;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\Sources\LeadKnowledgeSource;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Mockery;
use Tests\TestCase;

class LeadKnowledgeSourceTest extends TestCase
{
    /**
     * The agent's private tool-call rows have no text key and render as their raw JSON, tool
     * descriptions included; indexed, they carry the words of every customer question and bury the
     * real documents.
     */
    public function testOnlyPublicHumanReadableMessagesAreIndexed(): void
    {
        $this->assertTrue(LeadKnowledgeSource::isIndexable($this->message(['content' => 'whats your address?'])));
        $this->assertTrue(LeadKnowledgeSource::isIndexable($this->message(['content' => 'We open at 8:00 AM.', 'from_ia' => true])));

        $this->assertFalse(LeadKnowledgeSource::isIndexable($this->message(['content' => '', 'tool_calls' => [['name' => 'handoff_lead']]])), 'A tool round is the agent talking to itself');
        $this->assertFalse(LeadKnowledgeSource::isIndexable($this->message(['content' => '', 'tool_results' => [['name' => 'search_memory']]])));
        $this->assertFalse(LeadKnowledgeSource::isIndexable($this->message(['content' => 'internal note'], isPublic: 0)), 'Private rows never reach the index');
        $this->assertFalse(LeadKnowledgeSource::isIndexable($this->message(['content' => 'Summary of the thread'], verb: AgentMessageTypeEnum::AGENT_SUMMARY->value)));
        $this->assertFalse(LeadKnowledgeSource::isIndexable($this->message(['from_ia' => true, 'raw_data' => 'x'])), 'A payload with no text renders as JSON and is noise');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function message(array $payload, int $isPublic = 1, ?string $verb = null): Message
    {
        $message = new Message();
        $message->setRawAttributes(['message' => json_encode($payload), 'is_public' => $isPublic], true);

        $type = new MessageType();
        $type->setRawAttributes(['verb' => $verb ?? 'twilio-sms'], true);
        $message->setRelation('messageType', $type);

        return $message;
    }

    public function testBuildsStableTenantScopedProfileDocuments(): void
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->id = 11;
        $app->shouldReceive('get')->andReturnNull();

        $company = new Companies([
            'id' => 22,
            'name' => 'Acme Dominicana',
            'website' => 'https://acme.test',
        ]);
        $company->id = 22;
        $people = new People([
            'id' => 33,
            'name' => 'Ada Lovelace',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.test',
            'phone' => '8095550101',
        ]);
        $people->id = 33;
        $organization = new Organization([
            'id' => 44,
            'name' => 'Analytical Engines',
        ]);
        $organization->id = 44;
        $lead = new Lead([
            'id' => 55,
            'apps_id' => 11,
            'companies_id' => 22,
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'title' => 'Expansion opportunity',
            'email' => 'ada@example.test',
            'phone' => '8095550101',
            'description' => 'Interested in enterprise automation.',
        ]);
        $lead->id = 55;
        $lead->setRelation('app', $app);
        $lead->setRelation('company', $company);
        $lead->setRelation('people', $people);
        $lead->setRelation('organization', $organization);
        // Relations may be supplied as a base Support collection by callers/tests, not only
        // EloquentCollection (which is the only collection exposing modelKeys()).
        $lead->setRelation('socialChannels', new Collection());

        $documents = new LeadKnowledgeSource()->build($lead);

        $this->assertCount(4, $documents);
        $this->assertSame(
            ['lead-55-lead-55', 'lead-55-people-33', 'lead-55-company-22', 'lead-55-organization-44'],
            array_map(static fn (KnowledgeDocument $document): string => $document->id, $documents),
        );
        foreach ($documents as $document) {
            $this->assertSame(11, $document->metadata['apps_id']);
            $this->assertSame(22, $document->metadata['companies_id']);
            $this->assertSame(Lead::class, $document->metadata['entity_type']);
            $this->assertSame(55, $document->metadata['entity_id']);
            $this->assertNotSame('', $document->content);
        }
    }
}
