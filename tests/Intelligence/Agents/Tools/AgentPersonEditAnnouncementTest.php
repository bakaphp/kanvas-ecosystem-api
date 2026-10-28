<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ManagePersonContactTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetPersonAddressTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetPersonCustomFieldsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdatePersonTool;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Tests\Intelligence\Agents\Tools\Support\RecordingWorkflowActivity;
use Tests\TestCase;
use Tests\Workflow\Support\BuildsSyncPullRules;

/**
 * The agent-edit event is what keeps the Salesforce sync approval off the mutation path: the person tools
 * fire AGENT_UPDATED, a human's updatePeople fires only UPDATED.
 */
final class AgentPersonEditAnnouncementTest extends TestCase
{
    use BuildsSyncPullRules;
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'workflow', 'ecosystem'];

    private People $person;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingWorkflowActivity::$entityIds = [];
        $this->createSyncRule(
            app: app(Apps::class),
            event: WorkflowEnum::AGENT_UPDATED->value,
            modelClass: People::class,
            activityClass: RecordingWorkflowActivity::class,
            actionName: 'Record agent person edit ' . uniqid(),
        );

        $user = auth()->user();
        $this->person = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();
    }

    public function testEachPersonEditingToolAnnouncesTheEditOnce(): void
    {
        $id = $this->person->getId();

        $this->tool(UpdatePersonTool::class)->__invoke(person_id: $id, firstname: 'Agent');
        $this->assertSame([$id], $this->recordedAndReset());

        $this->tool(ManagePersonContactTool::class)->__invoke(person_id: $id, value: 'agent-edit@example.com', kind: 'email');
        $this->assertSame([$id], $this->recordedAndReset());

        $this->tool(SetPersonAddressTool::class)->__invoke(person_id: $id, address: '1 Agent Way', city: 'Santo Domingo');
        $this->assertSame([$id], $this->recordedAndReset());

        $this->tool(SetPersonCustomFieldsTool::class)->__invoke(person_id: $id, custom_fields: ['budget' => '500k']);
        $this->assertSame([$id], $this->recordedAndReset());
    }

    public function testACallThatChangesNothingDoesNotAnnounce(): void
    {
        $id = $this->person->getId();

        $this->tool(UpdatePersonTool::class)->__invoke(person_id: $id);

        $this->assertSame([], RecordingWorkflowActivity::$entityIds);
    }

    public function testMarkingAContactValidIsNotAnEditSalesforceHolds(): void
    {
        $id = $this->person->getId();
        $this->tool(ManagePersonContactTool::class)->__invoke(person_id: $id, value: 'flag@example.com', kind: 'email');
        $this->recordedAndReset();

        $this->tool(ManagePersonContactTool::class)->__invoke(person_id: $id, value: 'flag@example.com', action: 'mark_valid');

        $this->assertSame([], RecordingWorkflowActivity::$entityIds);
    }

    public function testAHumanEditThroughTheMutationDoesNotAnnounceAnAgentEdit(): void
    {
        $this->graphQL('
            mutation($id: ID!, $input: PeopleInput!) {
                updatePeople(id: $id, input: $input) { id }
            }
        ', [
            'id' => $this->person->getId(),
            'input' => [
                'firstname' => 'Human',
                'lastname' => 'Edit',
                'contacts' => [],
                'address' => [],
                'custom_fields' => [],
            ],
        ])->assertJsonPath('data.updatePeople.id', (string) $this->person->getId());

        $this->assertSame([], RecordingWorkflowActivity::$entityIds);
    }

    /**
     * @param class-string<UpdatePersonTool|ManagePersonContactTool|SetPersonAddressTool|SetPersonCustomFieldsTool> $class
     */
    private function tool(string $class): object
    {
        $user = auth()->user();

        return new $class()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
    }

    /**
     * @return list<int>
     */
    private function recordedAndReset(): array
    {
        $recorded = RecordingWorkflowActivity::$entityIds;
        RecordingWorkflowActivity::$entityIds = [];

        return $recorded;
    }
}
