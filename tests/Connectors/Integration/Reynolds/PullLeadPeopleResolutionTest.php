<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Actions\PullLeadAction;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class PullLeadPeopleResolutionTest extends TestCase
{
    private Apps $kanvasApp;
    private Users $actingUser;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->actingUser = auth()->user();
        $this->company = $this->actingUser->getCurrentCompany();
    }

    public function testMatchesPeopleByNameRecIdEvenWhenContactsDiffer(): void
    {
        $nameRecId = $this->uniqueId();
        $existing = $this->createPeople(
            [CustomFieldEnum::NAME_REC_ID->value => $nameRecId],
            email: $this->uniqueEmail(),
        );

        $lead = $this->pullLead($this->uniqueId(), $nameRecId, email: $this->uniqueEmail());

        $this->assertSame($existing->getId(), (int) $lead->people_id);
    }

    public function testCreatesANewPeopleWhenNameRecIdIsUnknownEvenIfContactsMatch(): void
    {
        $sharedEmail = $this->uniqueEmail();
        $sharedPhone = $this->uniquePhone();
        $existing = $this->createPeople([], email: $sharedEmail, phone: $sharedPhone);
        $nameRecId = $this->uniqueId();

        $lead = $this->pullLead(
            $this->uniqueId(),
            $nameRecId,
            email: $sharedEmail,
            phones: [['Type' => 'C', 'Num' => $sharedPhone]],
        );

        $this->assertNotSame($existing->getId(), (int) $lead->people_id);
        $this->assertSame(
            $nameRecId,
            (string) People::find($lead->people_id)->get(CustomFieldEnum::NAME_REC_ID->value)
        );
        $this->assertNull(
            People::find($existing->getId())->get(CustomFieldEnum::NAME_REC_ID->value),
            'The People that merely shares contacts must be left untouched.'
        );
    }

    public function testDifferentNameRecIdsSharingAPhoneStayTwoPeopleWithTwoLeads(): void
    {
        $sharedPhone = $this->uniquePhone();
        $firstNameRecId = $this->uniqueId();
        $secondNameRecId = $this->uniqueId();

        $firstLead = $this->pullLead(
            $this->uniqueId(),
            $firstNameRecId,
            phones: [['Type' => 'C', 'Num' => $sharedPhone]],
        );
        $secondLead = $this->pullLead(
            $this->uniqueId(),
            $secondNameRecId,
            phones: [['Type' => 'C', 'Num' => $sharedPhone]],
        );

        $this->assertNotSame($firstLead->getId(), $secondLead->getId());
        $this->assertNotSame((int) $firstLead->people_id, (int) $secondLead->people_id);
        $this->assertSame(
            $firstNameRecId,
            (string) People::find($firstLead->people_id)->get(CustomFieldEnum::NAME_REC_ID->value),
            'The first customer must keep their own NameRecId.'
        );
        $this->assertSame(
            $secondNameRecId,
            (string) People::find($secondLead->people_id)->get(CustomFieldEnum::NAME_REC_ID->value)
        );
    }

    public function testEnvelopeWithoutNameRecIdReusesAPeopleThatSharesContactsAndKeepsItsIdentifier(): void
    {
        $nameRecId = $this->uniqueId();
        $email = $this->uniqueEmail();
        $existing = $this->createPeople([CustomFieldEnum::NAME_REC_ID->value => $nameRecId], email: $email);

        $lead = $this->pullLead($this->uniqueId(), null, email: $email);

        $this->assertSame($existing->getId(), (int) $lead->people_id);
        $this->assertSame(
            $nameRecId,
            (string) People::find($existing->getId())->get(CustomFieldEnum::NAME_REC_ID->value),
            'A synthetic prospect key must never overwrite a real NameRecId.'
        );
    }

    public function testEnvelopeWithoutNameRecIdMatchesByAnyPhoneType(): void
    {
        $workPhone = $this->uniquePhone();
        $existing = $this->createPeople([], phone: $workPhone, phoneType: ContactTypeEnum::WORK_PHONE);

        $lead = $this->pullLead(
            $this->uniqueId(),
            null,
            phones: [['Type' => 'B', 'Num' => $this->formatPhone($workPhone)]],
        );

        $this->assertSame($existing->getId(), (int) $lead->people_id);
    }

    public function testLaterEnvelopeWithNameRecIdClaimsThePeopleCreatedWithoutOne(): void
    {
        $prospectId = $this->uniqueId();
        $email = $this->uniqueEmail();
        $nameRecId = $this->uniqueId();

        $firstLead = $this->pullLead($prospectId, null, email: $email);
        $secondLead = $this->pullLead($prospectId, $nameRecId, email: $email);

        $this->assertSame($firstLead->getId(), $secondLead->getId());
        $this->assertSame((int) $firstLead->people_id, (int) $secondLead->people_id);
        $this->assertSame(
            $nameRecId,
            (string) People::find($secondLead->people_id)->get(CustomFieldEnum::NAME_REC_ID->value)
        );
    }

    public function testPeopleStampedWithItsOwnProspectIdByAnOutboundPushIsReclaimed(): void
    {
        $prospectId = $this->uniqueId();
        $email = $this->uniqueEmail();
        $nameRecId = $this->uniqueId();

        $firstLead = $this->pullLead($prospectId, null, email: $email);
        $pushed = People::find($firstLead->people_id);
        $pushed->set(CustomFieldEnum::NAME_REC_ID->value, $prospectId);

        $secondLead = $this->pullLead($prospectId, $nameRecId, email: $email);

        $this->assertSame($firstLead->getId(), $secondLead->getId());
        $this->assertSame($pushed->getId(), (int) $secondLead->people_id);
        $this->assertSame(
            $nameRecId,
            (string) People::find($pushed->getId())->get(CustomFieldEnum::NAME_REC_ID->value),
            'The placeholder ProspectId must be replaced by the real NameRecId.'
        );
    }

    private function pullLead(
        string $prospectId,
        ?string $nameRecId,
        ?string $email = null,
        array $phones = []
    ): Lead {
        $customer = ['FirstName' => 'Trent', 'LastName' => 'Ferrell'];
        if ($nameRecId !== null) {
            $customer['NameRecId'] = $nameRecId;
        }

        $record = [
            'Identifier' => ['ProspectId' => $prospectId],
            'Prospect' => ['IsAiGenerated' => 'N'],
            'IndividualCustomer' => $customer,
        ];

        if ($email !== null) {
            $record['Email'] = ['MailTo' => $email];
        }

        if ($phones !== []) {
            $record['PhoneNumbers'] = ['Phone' => $phones];
        }

        return new PullLeadAction($this->kanvasApp, $this->company, $this->actingUser)->execute($record);
    }

    private function createPeople(
        array $customFields,
        ?string $email = null,
        ?string $phone = null,
        ContactTypeEnum $phoneType = ContactTypeEnum::CELLPHONE
    ): People {
        $contacts = [];
        if ($email !== null) {
            $contacts[] = ['value' => $email, 'contacts_types_id' => ContactTypeEnum::EMAIL->value, 'weight' => 0];
        }
        if ($phone !== null) {
            $contacts[] = ['value' => $phone, 'contacts_types_id' => $phoneType->value, 'weight' => 0];
        }

        return new CreatePeopleAction(PeopleData::from([
            'app' => $this->kanvasApp,
            'branch' => $this->company->defaultBranch,
            'user' => $this->actingUser,
            'firstname' => 'Existing',
            'lastname' => 'Customer',
            'contacts' => $contacts,
            'address' => [],
            'custom_fields' => $customFields,
            'skipDuplicateContactCheck' => true,
            'runWorkflow' => false,
        ]))->execute();
    }

    private function uniqueId(): string
    {
        return (string) random_int(10000000, 99999999);
    }

    private function uniqueEmail(): string
    {
        return 'reynolds-' . $this->uniqueId() . '@example.invalid';
    }

    private function uniquePhone(): string
    {
        return '229' . random_int(1000000, 9999999);
    }

    private function formatPhone(string $digits): string
    {
        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
    }
}
