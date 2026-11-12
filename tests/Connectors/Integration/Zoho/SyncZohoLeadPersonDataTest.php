<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Actions\SyncZohoLeadAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver as LeadReceiverData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Support\Setup;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\Actions\WireRuleAction;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Action;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\Modules\Module;

#[Group('serial')]
final class SyncZohoLeadPersonDataTest extends TestCase
{
    private const array FIELDS_MAP = [
        'street' => ['name' => 'Street', 'type' => 'string'],
        'city' => ['name' => 'City', 'type' => 'string'],
        'state' => ['name' => 'State', 'type' => 'string'],
        'zip' => ['name' => 'Zip_Code', 'type' => 'string'],
        'Date_of_Birth' => ['name' => 'Date_of_Birth', 'type' => 'string'],
    ];

    public function testNewLeadStoresAddressAndDobOnPersonAndLead(): void
    {
        $zohoLeadId = 'zoho-' . uniqid();
        $lead = $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '742 Evergreen Terrace'));

        $this->assertInstanceOf(Lead::class, $lead);
        $this->assertSame('1985-04-12', $lead->people->dob->format('Y-m-d'));

        $address = $lead->people->getDefaultAddress();
        $this->assertSame('742 Evergreen Terrace', $address->address);
        $this->assertSame('Springfield', $address->city);
        $this->assertSame('IL', $address->state);
        $this->assertSame('62704', $address->zip);

        $this->assertSame('742 Evergreen Terrace', $lead->get('street'));
        $this->assertEquals('62704', $lead->get('zip'));
        $this->assertSame('1985-04-12', $lead->get('Date_of_Birth'));
    }

    public function testExistingLeadUpdatesPersonAddressAndDob(): void
    {
        $zohoLeadId = 'zoho-' . uniqid();
        $first = $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '742 Evergreen Terrace'));
        $originalAddressId = $first->people->getDefaultAddress()->getKey();

        $lead = $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '1 Infinite Loop'));

        $people = $lead->people->fresh();
        $this->assertSame('1 Infinite Loop', $people->getDefaultAddress()->address);
        $this->assertSame($originalAddressId, $people->getDefaultAddress()->getKey());
        $this->assertSame(1, $people->address()->count());
        $this->assertSame('1985-04-12', $people->dob->format('Y-m-d'));
        $this->assertSame('1 Infinite Loop', $lead->get('street'));
    }

    public function testExistingLeadMergesNewContactsWithoutDroppingTheOldOnes(): void
    {
        $zohoLeadId = 'zoho-' . uniqid();
        $first = $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '742 Evergreen Terrace'));
        $originalEmail = $first->people->getEmails()->first()->value;

        $lead = $this->syncAction($zohoLeadId)->execute(
            $this->zohoRecord($zohoLeadId, '742 Evergreen Terrace', ['Phone' => '(939) 555-0113'])
        );

        $people = $lead->people->fresh();
        $this->assertCount(2, $people->getEmails(), 'the first sync email stays, the second one is added');
        $this->assertContains($originalEmail, $people->getEmails()->pluck('value')->all());
        $this->assertSame('9395550113', $people->getPhones()->first()->value);
    }

    public function testExistingLeadKeepsThePersonNameWhenZohoSendsNone(): void
    {
        $zohoLeadId = 'zoho-' . uniqid();
        $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '742 Evergreen Terrace'));

        $record = $this->zohoRecord($zohoLeadId, '742 Evergreen Terrace')->getData();
        unset($record['First_Name'], $record['Last_Name'], $record['Full_Name']);
        $lead = $this->syncAction($zohoLeadId)->execute(new Record($record, Mockery::mock(Module::class)));

        $people = $lead->people->fresh();
        $this->assertSame('Homer', $people->firstname);
        $this->assertSame('Simpson', $people->lastname);
    }

    public function testMirrorsThePersonsAddressOntoTheLeadsCustomFields(): void
    {
        $zohoLeadId = 'zoho-' . uniqid();

        $lead = $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '742 Evergreen Terrace'));

        $this->assertSame('742 Evergreen Terrace', $lead->get('address'));
        $this->assertSame('Springfield', $lead->get('city'));
        $this->assertSame('IL', $lead->get('state'));
        $this->assertEquals('62704', $lead->get('zip'));
    }

    /**
     * Zoho fires the webhook on every edit, so a pull that reached a rule pushing the lead back
     * would overwrite what was just changed in Zoho (a reassigned owner) with Kanvas's old value.
     */
    public function testUpdatingAnExistingLeadFiresNoWorkflows(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $zohoLeadId = 'zoho-' . uniqid();
        $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '742 Evergreen Terrace'));

        $action = Action::factory()->create(['kind' => ActionKindEnum::WORKFLOW->value]);
        $rule = new WireRuleAction(
            $app,
            $company->getId(),
            Lead::class,
            WorkflowEnum::CREATE_CUSTOM_FIELD->value,
            $action->model_name,
            'Zoho pull silence test',
            'test',
        )->execute();
        $this->assertNotNull($rule);

        try {
            $before = StoredWorkflow::query()->where('class', DynamicRuleWorkflow::class)->count();

            $lead = $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '1 Infinite Loop'));

            $this->assertSame('1 Infinite Loop', $lead->get('street'));
            $this->assertSame($before, StoredWorkflow::query()->where('class', DynamicRuleWorkflow::class)->count());
        } finally {
            $rule->delete();
            $action->delete();
        }
    }

    private function syncAction(string $zohoLeadId): SyncZohoLeadAction
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(CustomFieldEnum::FIELDS_MAP->value, self::FIELDS_MAP);

        new Setup($app, $user, $company)->run();

        return new SyncZohoLeadAction(
            $app,
            $company,
            $this->receiver(),
            $zohoLeadId
        );
    }

    private function receiver(): LeadReceiver
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateLeadReceiverAction(
            new LeadReceiverData(
                app: $app,
                branch: $company->defaultBranch,
                user: $user,
                agent: $user,
                name: 'zoho-test',
                source: 'zoho',
                isDefault: true
            )
        )->execute();
    }

    private function zohoRecord(string $zohoLeadId, string $street, array $extra = []): Record
    {
        return new Record($extra + [
            'id' => $zohoLeadId,
            'First_Name' => 'Homer',
            'Last_Name' => 'Simpson',
            'Full_Name' => 'Homer Simpson',
            'Email' => 'homer-' . uniqid() . '@example.com',
            'Lead_Status' => 'New',
            'Street' => $street,
            'City' => 'Springfield',
            'State' => 'IL',
            'Zip_Code' => '62704',
            'Date_of_Birth' => '1985-04-12',
        ], Mockery::mock(Module::class));
    }
}
