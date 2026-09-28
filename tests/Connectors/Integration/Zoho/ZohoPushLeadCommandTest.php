<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * The push path itself needs live Zoho credentials, so this covers the parts that break without
 * them: resolving the lead by id or uuid, and assembling the payload the command would send.
 */
final class ZohoPushLeadCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm'];

    public function testDryRunPrintsThePayloadForALeadId(): void
    {
        $lead = $this->createTestLead();

        $this->artisan('kanvas:zoho-push-lead', [
            'leads' => (string) $lead->getId(),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Lead ' . $lead->getId())
            ->expectsOutputToContain('"First_Name": "Zoho"')
            ->assertSuccessful();
    }

    public function testDryRunAlsoAcceptsTheLeadUuid(): void
    {
        $lead = $this->createTestLead();

        $this->artisan('kanvas:zoho-push-lead', [
            'leads' => $lead->uuid,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('"Last_Name": "Resubmit"')
            ->assertSuccessful();
    }

    public function testUnknownLeadIsReportedAndFailsTheCommand(): void
    {
        $this->artisan('kanvas:zoho-push-lead', ['leads' => '999999999'])
            ->expectsOutputToContain('Lead 999999999')
            ->assertFailed();
    }

    private function createTestLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $branch = $company->defaultBranch;

        $peopleDto = new PeopleDto(
            app: $app,
            branch: $branch,
            user: $user,
            firstname: 'Zoho',
            contacts: Contact::collect(
                [
                    [
                        'value' => fake()->phoneNumber(),
                        'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                        'weight' => 100,
                    ],
                ],
                DataCollection::class
            ),
            address: Address::collect([], DataCollection::class),
            lastname: 'Resubmit',
        );

        $people = new CreatePeopleAction($peopleDto)->execute();

        $leadType = LeadType::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('name', 'Warm')
            ->firstOrFail();

        $leadData = new LeadData(
            app: $app,
            branch: $branch,
            user: $user,
            title: 'Zoho Resubmit Test Lead',
            pipeline_stage_id: 0,
            people: new PeopleDto(
                $app,
                $branch,
                $user,
                (string) $people->firstname,
                Contact::collect($people->contacts()->get()->toArray(), DataCollection::class),
                Address::collect([], DataCollection::class),
                (string) $people->lastname,
                $people->id,
            ),
            leads_owner_id: $user->getId(),
            status_id: 0,
            type_id: $leadType->getId(),
            source_id: 0,
        );

        return new CreateLeadAction($leadData)->execute();
    }
}
