<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Summary;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Summary\LeadSummaryRepository;
use Kanvas\Intelligence\Agents\Enums\AgentRunConfigurationEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\MakesLeadConversations;

/**
 * Serial: the lead summary switch is an app setting, in Redis, seen by every process.
 */
#[Group('serial')]
final class LeadSummaryInvalidationTest extends TestCase
{
    use DatabaseTransactions;
    use MakesLeadConversations;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(AgentRunConfigurationEnum::LEAD_SUMMARY->value, 1);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->del(AgentRunConfigurationEnum::LEAD_SUMMARY->value);

        parent::tearDown();
    }

    public function testAnEditedNoteIsRebuiltAndADeliveryStatusIsNot(): void
    {
        $lead = $this->makeLead();
        $note = $this->leadMessage($lead, 'Staff: price locked at 30k');
        $this->leadMessage($lead, 'ok');
        $this->updateSummary($lead, [$this->summaryAnswer([])]);

        $status = $note->getMessage();
        $status['delivery_status'] = 'delivered';
        $note->message = $status;
        $note->saveOrFail();
        $this->assertNull(LeadSummaryRepository::state($lead)->rebuildFrom, 'A status rewrite is not an edit');

        $edited = $note->fresh()->getMessage();
        $edited['content'] = 'Staff: price locked at 28k';
        $note->message = $edited;
        $note->saveOrFail();

        $this->assertSame($note->getId(), LeadSummaryRepository::state($lead)->rebuildFrom);
    }

    public function testADeletedMessageIsRebuilt(): void
    {
        $lead = $this->makeLead();
        $first = $this->leadMessage($lead, 'I will come Saturday at 10');
        $this->leadMessage($lead, 'see you then');
        $this->updateSummary($lead, [$this->summaryAnswer([])]);

        $first->is_deleted = 1;
        $first->saveOrFail();

        $this->assertSame($first->getId(), LeadSummaryRepository::state($lead)->rebuildFrom);
    }

    public function testADoubleEncodedDeliveryStatusDoesNotInvalidateTheSummary(): void
    {
        $lead = $this->makeLead();
        $note = $this->leadMessage($lead, 'Staff: price locked at 30k');
        $payload = $note->getMessage();
        $note->message = json_encode(json_encode($payload));
        $note->saveOrFail();
        $this->updateSummary($lead, [$this->summaryAnswer([])]);

        $payload['delivery_status'] = 'delivered';
        $note->message = json_encode(json_encode($payload));
        $note->saveOrFail();

        $this->assertNull(LeadSummaryRepository::state($lead)->rebuildFrom);

        $payload['content'] = 'Staff: price locked at 28k';
        $note->message = json_encode(json_encode($payload));
        $note->saveOrFail();

        $this->assertSame($note->getId(), LeadSummaryRepository::state($lead)->rebuildFrom);
    }

    public function testAnOlderMessageAttachedLateIsRebuiltAndANewOneIsNot(): void
    {
        $lead = $this->makeLead();
        $older = $this->leadMessage($lead, 'synced late from the CRM', attach: false);
        $this->leadMessage($lead, 'hello');
        $this->leadMessage($lead, 'hi there');
        $this->updateSummary($lead, [$this->summaryAnswer([])]);

        $this->leadMessage($lead, 'a brand new message');
        $this->assertNull(LeadSummaryRepository::state($lead)->rebuildFrom, 'New ids are the marker\'s job');

        $older->addEntity($lead);
        $this->assertSame($older->getId(), LeadSummaryRepository::state($lead)->rebuildFrom);
    }
}
