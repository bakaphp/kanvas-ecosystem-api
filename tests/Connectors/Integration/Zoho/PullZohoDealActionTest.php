<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\PullZohoDealAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\Models\Deal;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\TestCase;

final class PullZohoDealActionTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testCreatesTheDealLinkedToItsLeadAndKeepsTheZohoIds(): void
    {
        $company = $this->makeZohoCompany();
        $lead = $this->makeLead($company);
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-9');

        $info = [
            'id' => 'zd-9',
            'Deal_Name' => 'Acme funding',
            'Description' => 'From Zoho',
            'ZCRM_Lead_ID' => 'zl-9',
            'Business_Name' => 'Acme LLC',
            'Amount_Requested' => 50000,
        ];

        $deal = $this->action($company, 'zd-9', $info)->execute();

        $this->assertInstanceOf(Deal::class, $deal);
        $this->assertTrue($deal->wasRecentlyCreated);
        $this->assertSame('Acme funding', $deal->title);
        $this->assertSame('From Zoho', $deal->description);
        $this->assertSame($lead->getId(), (int) $deal->leads_id);
        $this->assertSame((int) $lead->people_id, (int) $deal->people_id);
        $this->assertSame('zd-9', $deal->get(CustomFieldEnum::ZOHO_DEAL_ID->value));
        $this->assertSame('Acme funding', $deal->get(CustomFieldEnum::ZOHO_DEAL_RAW->value)['Deal_Name']);
        $this->assertSame('Acme LLC', $deal->get('business_legal_name'));
        $this->assertSame(50000, $deal->get('funding_amount_requested'));
    }

    public function testACompanyMapInTheLeadShapeIsReadOnThePull(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_DEAL_FIELDS_MAP->value, [
            'zip' => ['name' => 'Zip_Code', 'type' => 'string'],
            'amount_requested' => ['name' => 'Amount_Requested', 'type' => 'currency'],
        ]);

        $deal = $this->action($company, 'zd-12', [
            'id' => 'zd-12',
            'Deal_Name' => 'Mapped',
            'Zip_Code' => '33033',
            'Amount_Requested' => 25000,
        ])->execute();

        $this->assertSame('33033', (string) $deal->get('zip'));
        $this->assertSame(25000, $deal->get('amount_requested'));
    }

    public function testCreatesThePersonFromTheDealWhenNobodyMatches(): void
    {
        $company = $this->makeZohoCompany();
        $email = 'marge-' . uniqid() . '@example.com';

        $deal = $this->action($company, 'zd-20', [
            'id' => 'zd-20',
            'Deal_Name' => 'Marge funding',
            'First_Name' => 'Marge',
            'Last_Name' => 'Bouvier',
            'Email' => $email,
            'Phone' => '(939) 555-0114',
            'Street' => '742 Evergreen Terrace',
            'City' => 'Springfield',
            'State' => 'IL',
            'Zip_Code' => '62704',
            'Date_of_Birth' => '1956-03-19',
        ])->execute();

        $people = $deal->people;
        $this->assertNotNull($people);
        $this->assertSame('Marge', $people->firstname);
        $this->assertSame('Bouvier', $people->lastname);
        $this->assertSame('1956-03-19', $people->dob->format('Y-m-d'));
        $this->assertSame($email, $people->getEmails()->first()->value);
        $this->assertSame('9395550114', $people->getPhones()->first()->value);

        $address = $people->getDefaultAddress();
        $this->assertSame('742 Evergreen Terrace', $address->address);
        $this->assertSame('Springfield', $address->city);
        $this->assertSame('IL', $address->state);
        $this->assertSame('62704', $address->zip);
    }

    public function testTheDealOverwritesItsLeadsPersonWithWhatItCarries(): void
    {
        $company = $this->makeZohoCompany();
        $lead = $this->makeLead($company);
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-21');
        $originalEmails = $lead->people->getEmails()->count();

        $deal = $this->action($company, 'zd-21', [
            'id' => 'zd-21',
            'Deal_Name' => 'Updated person',
            'ZCRM_Lead_ID' => 'zl-21',
            'First_Name' => 'Marge',
            'Phone' => '(939) 555-0115',
            'Street' => '1 Infinite Loop',
            'City' => 'Cupertino',
            'State' => 'CA',
            'Zip_Code' => '95014',
        ])->execute();

        $people = $deal->people->fresh();
        $this->assertSame((int) $lead->people_id, $people->getId());
        $this->assertSame('Marge', $people->firstname);
        $this->assertSame($lead->people->lastname, $people->lastname, 'a field the deal does not carry is left alone');
        $this->assertSame($originalEmails, $people->getEmails()->count());
        $this->assertContains('9395550115', $people->getPhones()->pluck('value')->all());
        $this->assertSame('1 Infinite Loop', $people->getDefaultAddress()->address);
        $this->assertSame('95014', $people->getDefaultAddress()->zip);
    }

    public function testADealThatNamesNobodyLeavesTheLeadsPersonAlone(): void
    {
        $company = $this->makeZohoCompany();
        $lead = $this->makeLead($company);
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-22');
        $before = $lead->people->fresh();

        $deal = $this->action($company, 'zd-22', [
            'id' => 'zd-22',
            'Deal_Name' => 'Nobody named',
            'ZCRM_Lead_ID' => 'zl-22',
            'Amount_Requested' => 1000,
        ])->execute();

        $people = $deal->people->fresh();
        $this->assertSame($before->firstname, $people->firstname);
        $this->assertSame($before->lastname, $people->lastname);
        $this->assertSame($before->address()->count(), $people->address()->count());
        $this->assertSame($before->contacts()->count(), $people->contacts()->count());
    }

    public function testADealWithoutNameFieldsSplitsItsContactName(): void
    {
        $company = $this->makeZohoCompany();

        $deal = $this->action($company, 'zd-23', [
            'id' => 'zd-23',
            'Deal_Name' => 'Contact name only',
            'Contact_Name' => ['name' => 'Ned Flanders', 'id' => '555'],
            'Email' => 'ned-' . uniqid() . '@example.com',
        ])->execute();

        $this->assertSame('Ned', $deal->people->firstname);
        $this->assertSame('Flanders', $deal->people->lastname);
    }

    public function testReadsThePersonFromTheLinkedZohoContact(): void
    {
        $company = $this->makeZohoCompany();
        $email = 'ned-' . uniqid() . '@example.com';

        $deal = $this->action(
            $company,
            'zd-25',
            [
                'id' => 'zd-25',
                'Deal_Name' => 'Contact linked',
                'Contact_Name' => ['name' => 'Ned Flanders', 'id' => '777'],
            ],
            [
                'id' => '777',
                'First_Name' => 'Nedward',
                'Last_Name' => 'Flanders',
                'Email' => $email,
                'Mobile' => '(939) 555-0116',
                'Mailing_Street' => '744 Evergreen Terrace',
                'Mailing_City' => 'Springfield',
                'Mailing_State' => 'IL',
                'Mailing_Zip' => '62704',
                'Date_of_Birth' => '1960-02-29',
            ]
        )->execute();

        $people = $deal->people;
        $this->assertSame('Nedward', $people->firstname);
        $this->assertSame('Flanders', $people->lastname);
        $this->assertSame($email, $people->getEmails()->first()->value);
        $this->assertSame('9395550116', $people->getPhones()->first()->value);
        $this->assertSame('1960-02-29', $people->dob->format('Y-m-d'));
        $this->assertSame('744 Evergreen Terrace', $people->getDefaultAddress()->address);
        $this->assertSame('62704', $people->getDefaultAddress()->zip);
    }

    public function testTheDealsOwnFieldsWinAndTheLinkedContactFillsTheGaps(): void
    {
        $company = $this->makeZohoCompany();
        $contactEmail = 'ned-' . uniqid() . '@example.com';

        $deal = $this->action(
            $company,
            'zd-26',
            [
                'id' => 'zd-26',
                'Deal_Name' => 'Address edited on the deal',
                'Contact_Name' => ['name' => 'Ned Flanders', 'id' => '778'],
                'Street' => '1 Infinite Loop',
                'City' => 'Cupertino',
                'State' => 'CA',
                'Zip_Code' => '95014',
                'Email' => '',
            ],
            [
                'id' => '778',
                'First_Name' => 'Ned',
                'Last_Name' => 'Flanders',
                'Email' => $contactEmail,
                'Mailing_Street' => '744 Evergreen Terrace',
                'Mailing_City' => 'Springfield',
                'Mailing_State' => 'IL',
                'Mailing_Zip' => '62704',
            ]
        )->execute();

        $address = $deal->people->getDefaultAddress();
        $this->assertSame('1 Infinite Loop', $address->address);
        $this->assertSame('Cupertino', $address->city);
        $this->assertSame('CA', $address->state);
        $this->assertSame('95014', $address->zip);
        $this->assertSame('1 Infinite Loop', $deal->get('address'));
        $this->assertSame($contactEmail, $deal->people->getEmails()->first()->value, 'an empty deal field does not shadow the contact');
    }

    public function testMirrorsThePersonsAddressOntoTheDealsCustomFields(): void
    {
        $company = $this->makeZohoCompany();

        $deal = $this->action($company, 'zd-27', [
            'id' => 'zd-27',
            'Deal_Name' => 'Mirrored address',
            'First_Name' => 'Maude',
            'Street' => '744 Evergreen Terrace',
            'City' => 'Springfield',
            'State' => 'IL',
            'Zip_Code' => '62704',
        ])->execute();

        $this->assertSame('744 Evergreen Terrace', $deal->get('address'));
        $this->assertSame('Springfield', $deal->get('city'));
        $this->assertSame('IL', $deal->get('state'));
        $this->assertEquals('62704', $deal->get('zip'));
    }

    public function testADealWithNoPersonAtAllIsCreatedWithoutOne(): void
    {
        $company = $this->makeZohoCompany();

        $deal = $this->action($company, 'zd-24', ['id' => 'zd-24', 'Deal_Name' => 'Anonymous'])->execute();

        $this->assertSame(0, (int) $deal->people_id);
    }

    public function testASecondPullUpdatesTheSameDealInsteadOfAddingOne(): void
    {
        $company = $this->makeZohoCompany();
        $info = ['id' => 'zd-10', 'Deal_Name' => 'First title'];

        $first = $this->action($company, 'zd-10', $info)->execute();
        $second = $this->action($company, 'zd-10', ['Deal_Name' => 'Renamed in Zoho'] + $info)->execute();

        $this->assertSame($first->getId(), $second->getId());
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame('Renamed in Zoho', $second->fresh()->title);
        $this->assertSame(
            1,
            Deal::getByCustomFieldBuilderTransactionSafe(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-10', $company)->count()
        );
    }

    public function testReturnsNullWhenZohoHasNoSuchDeal(): void
    {
        $company = $this->makeZohoCompany();

        $this->assertNull($this->action($company, 'zd-missing', [])->execute());
        $this->assertNull(Deal::getByCustomField(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-missing', $company));
    }

    public function testFetchesTheDealFromZohoWhenNoPayloadIsHandedIn(): void
    {
        $company = $this->makeZohoCompany();

        $deal = $this->action($company, 'zd-11', ['id' => 'zd-11', 'Deal_Name' => 'Fetched'])->execute();

        $this->assertSame('Fetched', $deal->title);
    }

    private function action(
        Companies $company,
        string $zohoDealId,
        array $fetched,
        array $contact = []
    ): PullZohoDealAction {
        return new class (
            app(Apps::class),
            $company,
            auth()->user(),
            $zohoDealId,
            $fetched,
            $contact
        ) extends PullZohoDealAction {
            public function __construct(
                AppInterface $app,
                CompanyInterface $company,
                UserInterface $user,
                string $zohoDealId,
                private array $fetched,
                private array $contact
            ) {
                parent::__construct($app, $company, $user, $zohoDealId);
            }

            protected function fetchDeal(): array
            {
                return $this->fetched;
            }

            protected function fetchContact(string $contactId): array
            {
                return $this->contact;
            }

            protected function pullAttachments(Deal $deal): void
            {
            }
        };
    }
}
