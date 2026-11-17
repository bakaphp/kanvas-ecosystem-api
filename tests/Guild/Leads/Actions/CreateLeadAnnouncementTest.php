<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\Rule;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;
use Tests\Workflow\Support\WatchesWorkflowEvents;

/**
 * A lead an import brings in (`runWorkflow: false`) is history, not a prospect who just wrote in:
 * nothing that listens for a new lead may hear about it. One created by hand fires `created` (the
 * reach-out) and `trigger-ai` (its AI mode), the second telling the mode rule that a reach-out applies it.
 */
final class CreateLeadAnnouncementTest extends TestCase
{
    use DatabaseTransactions;
    use WatchesWorkflowEvents;

    protected $connectionsToTransact = [null, 'crm', 'social', 'workflow', 'intelligence'];

    private Rule $created;
    private Rule $aiTrigger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->created = $this->watchWorkflowEvent(WorkflowEnum::CREATED->value, Lead::class);
        $this->aiTrigger = $this->watchWorkflowEvent(WorkflowEnum::TRIGGER_AI->value, Lead::class);
    }

    public function testALeadCreatedByHandIsAnnounced(): void
    {
        $lead = new CreateLeadAction($this->leadData(runWorkflow: true))->execute();

        $this->assertCount(1, $this->firedWith($this->created));
        $this->assertTrue($this->firedWith($this->created)->first()['company']->is($lead->company));

        $trigger = $this->firedWith($this->aiTrigger);
        $this->assertCount(1, $trigger);
        $this->assertSame(TriggersEnum::NEW_LEAD->value, $trigger->first()['trigger_type']);
        $this->assertTrue(
            $trigger->first()[Lead::REACH_OUT_APPLIES_MODE] ?? false,
            'the reach-out gives the lead its mode when an agent reaches out, so the mode rule must be told'
        );
    }

    public function testAnImportedLeadIsNotAnnounced(): void
    {
        new CreateLeadAction($this->leadData(runWorkflow: false))->execute();

        $this->assertCount(0, $this->firedWith($this->created));
        $this->assertCount(0, $this->firedWith($this->aiTrigger));
    }

    /**
     * An import switches the lead's writes off and still says the lead is new.
     */
    public function testAnImportThatAnnouncesTheTriggerItselfIsHeardEvenWithItsWritesOff(): void
    {
        $updated = $this->watchWorkflowEvent(WorkflowEnum::UPDATED->value, Lead::class);
        $lead = new CreateLeadAction($this->leadData(runWorkflow: false))->execute();

        $lead->announceAiTrigger(TriggersEnum::NEW_LEAD->value);

        $trigger = $this->firedWith($this->aiTrigger);
        $this->assertCount(1, $trigger);
        $this->assertArrayNotHasKey(Lead::REACH_OUT_APPLIES_MODE, $trigger->first());
        $this->assertCount(0, $this->firedWith($this->created));

        $lead->fireWorkflow(WorkflowEnum::UPDATED->value);
        $this->assertCount(0, $this->firedWith($updated), 'its own writes stay switched off');
    }

    private function leadData(bool $runWorkflow): LeadData
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $branch = $user->getCurrentCompany()->defaultBranch;

        return new LeadData(
            app: $app,
            branch: $branch,
            user: $user,
            title: 'Announcement ' . uniqid(),
            pipeline_stage_id: 0,
            people: new PeopleData(
                app: $app,
                branch: $branch,
                user: $user,
                firstname: 'Ana',
                contacts: Contact::collect(
                    [[
                        'value' => '+1809555' . random_int(1000, 9999),
                        'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                        'weight' => 100,
                    ]],
                    DataCollection::class
                ),
                address: Address::collect([], DataCollection::class),
                lastname: 'Import ' . uniqid(),
            ),
            leads_owner_id: $user->getId(),
            runWorkflow: $runWorkflow,
        );
    }
}
