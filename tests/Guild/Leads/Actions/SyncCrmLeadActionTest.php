<?php

declare(strict_types=1);

namespace Tests\Guild\Leads\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Actions\PullLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Services\ReynoldsCrmDriver;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleRelationship;
use Kanvas\Guild\Leads\Actions\SyncCrmLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmLeadInfo;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSync;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSyncResult;
use Kanvas\Guild\Leads\Enums\CrmLeadSyncOutcomeEnum;
use Kanvas\Guild\Leads\Enums\CrmLeadSyncSettingEnum;
use Kanvas\Guild\Leads\Exceptions\CrmLeadSyncException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Serial: it sets and restores a company setting, which is Redis-backed and shared by every parallel process.
 */
#[Group('serial')]
final class SyncCrmLeadActionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * ecosystem is not optional: custom fields are written on that connection. intelligence is where a
     * do-not-contact hand-off writes.
     */
    protected $connectionsToTransact = [null, 'crm', 'ecosystem', 'intelligence', 'social'];

    private mixed $previousDealerNumber = null;

    private array $lastIdentity = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $company = $this->company();
        $this->previousDealerNumber = $company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value);
        $company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, 'TESTDEALER001');
    }

    protected function tearDown(): void
    {
        $key = ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value;
        $this->previousDealerNumber === null
            ? $this->company()->del($key)
            : $this->company()->set($key, $this->previousDealerNumber);

        parent::tearDown();
    }

    public function testCreatesThePeopleAndTheLeadWhenNeitherExists(): void
    {
        $input = $this->payload();

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::CREATED_ALL, $result->outcome);
        $this->assertTrue($result->peopleCreated);
        $this->assertTrue($result->leadCreated);
        $this->assertFalse($result->replayed);

        $people = $result->people;
        $lead = $result->lead;

        $this->assertSame('Ana', $people->firstname);
        $this->assertSame($input['identity']['client_id'], (string) $people->get(CustomFieldEnum::CLIENT_ID->value));
        $this->assertSame($input['identity']['lead_id'], (string) $lead->get(CustomFieldEnum::PROSPECT_ID->value));
        $this->assertSame('es', $people->get(CustomFieldEnum::LANGUAGE->value));
        $this->assertSame('Walk-In', $lead->get(CustomFieldEnum::PROSPECT_TYPE->value));
        $this->assertSame($people->getId(), (int) $lead->people_id);
        $this->assertSame('Ana Perez', $lead->title);
        $this->assertSame((int) auth()->user()->getId(), (int) $lead->leads_owner_id);
        $this->assertSame('Walk-In', $lead->type->name);
        $this->assertSame('*Drove By', $lead->source->name);
        $this->assertSame('Active', $lead->status->name, 'the CRM calls a live prospect Open, the catalog Active');
        $this->assertSame(
            [$input['customer']['emails'][0]],
            $people->getEmails()->pluck('value')->all()
        );
        $this->assertSame('7875550101', $people->getCellPhones()->first()->value);
        $this->assertSame('7875550103', $people->contacts()->where('contacts_types_id', 8)->first()->value);
        $this->assertSame('123 Calle Sol', $people->getDefaultAddress()->address);
    }

    public function testASecondRequestWithTheSameLeadIdOpensTheLeadAndCreatesNothing(): void
    {
        $first = $this->sync($this->payload());
        $leadsBefore = Lead::query()->where('people_id', $first->people->getId())->count();

        $second = $this->sync($this->payload([
            'identity' => $this->identity($first),
            'customer' => ['emails' => ['second.' . uniqid() . '@example.com']],
        ]));

        $this->assertSame(CrmLeadSyncOutcomeEnum::OPENED, $second->outcome);
        $this->assertFalse($second->peopleCreated);
        $this->assertFalse($second->leadCreated);
        $this->assertSame($first->lead->getId(), $second->lead->getId());
        $this->assertSame($leadsBefore, Lead::query()->where('people_id', $first->people->getId())->count());
        $this->assertCount(2, $second->people->getEmails(), 'emails are only ever added');
    }

    public function testTheSameRequestIdIsReplayedWithoutRunningAgain(): void
    {
        $input = $this->payload();

        $first = $this->sync($input);
        $replay = $this->sync($input);

        $this->assertTrue($replay->replayed);
        $this->assertSame($first->lead->getId(), $replay->lead->getId());
        $this->assertSame($first->outcome, $replay->outcome);
        $this->assertSame(1, People::query()->where('id', $first->people->getId())->count());
    }

    public function testAnActiveLeadWithoutAProspectIdIsAdopted(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $lead = $this->createLead($people, 'Active');

        $result = $this->sync($this->payload(['customer' => ['emails' => [$email]]]));

        $this->assertSame(CrmLeadSyncOutcomeEnum::ADOPTED, $result->outcome);
        $this->assertFalse($result->peopleCreated);
        $this->assertFalse($result->leadCreated);
        $this->assertSame($lead->getId(), $result->lead->getId());
        $this->assertSame(
            $this->identityValue('lead_id'),
            (string) $lead->refresh()->get(CustomFieldEnum::PROSPECT_ID->value)
        );
        $this->assertSame($this->identityValue('client_id'), (string) $people->get(CustomFieldEnum::CLIENT_ID->value));
    }

    public function testAClosedLeadIsNotAdoptedAndANewOneIsCreatedOnTheSamePeople(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $closed = $this->createLead($people, 'Closed');

        $result = $this->sync($this->payload(['customer' => ['emails' => [$email]]]));

        $this->assertSame(CrmLeadSyncOutcomeEnum::CREATED_LEAD, $result->outcome);
        $this->assertFalse($result->peopleCreated);
        $this->assertTrue($result->leadCreated);
        $this->assertSame($people->getId(), $result->people->getId());
        $this->assertNotSame($closed->getId(), $result->lead->getId());
    }

    public function testAnActiveLeadOfAnotherProspectIsLeftAlone(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $other = $this->createLead($people, 'Active');
        $other->set(CustomFieldEnum::PROSPECT_ID->value, 'OTHER-' . uniqid());

        $result = $this->sync($this->payload(['customer' => ['emails' => [$email]]]));

        $this->assertSame(CrmLeadSyncOutcomeEnum::CREATED_LEAD, $result->outcome);
        $this->assertNotSame($other->getId(), $result->lead->getId());
    }

    public function testTheMostRecentlyUpdatedActiveLeadIsTheOneAdopted(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $older = $this->createLead($people, 'Active');
        $newer = $this->createLead($people, 'Active');
        DB::connection('crm')->table('leads')->where('id', $older->getId())->update(['updated_at' => now()->addHour()]);

        $result = $this->sync($this->payload(['customer' => ['emails' => [$email]]]));

        $this->assertSame($older->getId(), $result->lead->getId());
        $this->assertNotSame($newer->getId(), $result->lead->getId());
    }

    public function testWithoutAProspectIdTheLatestActiveLeadIsOpened(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $lead = $this->createLead($people, 'Active');
        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['identity']['lead_id'] = null;

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::OPENED, $result->outcome);
        $this->assertSame($lead->getId(), $result->lead->getId());
        $this->assertContains('lead_id_missing', array_column($result->warnings, 'code'));
    }

    /**
     * A lead is never created without the CRM's id for it: with nothing to open only the people is made.
     */
    public function testWithoutAProspectIdAndNoLeadToOpenOnlyThePeopleIsCreated(): void
    {
        $email = $this->uniqueEmail();
        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['identity']['lead_id'] = null;
        unset($input['lead']);

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::PEOPLE_ONLY, $result->outcome);
        $this->assertNull($result->lead);
        $this->assertTrue($result->peopleCreated);
        $this->assertFalse($result->leadCreated);
        $this->assertSame($input['identity']['client_id'], (string) $result->people->get(CustomFieldEnum::CLIENT_ID->value));
        $this->assertSame([$email], $result->people->getEmails()->pluck('value')->all());
        $this->assertSame(0, Lead::query()->where('people_id', $result->people->getId())->count());
        $this->assertContains('lead_id_missing', array_column($result->warnings, 'code'));
    }

    public function testWithoutAProspectIdAClosedLeadIsNotReplacedByANewOne(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $closed = $this->createLead($people, 'Closed');
        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['identity']['lead_id'] = null;

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::PEOPLE_ONLY, $result->outcome);
        $this->assertNull($result->lead);
        $this->assertFalse($result->peopleCreated);
        $this->assertSame($people->getId(), $result->people->getId());
        $this->assertSame($input['identity']['client_id'], (string) $people->refresh()->get(CustomFieldEnum::CLIENT_ID->value));
        $this->assertSame(
            [$closed->getId()],
            Lead::query()->where('people_id', $people->getId())->notDeleted()->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function testAPeopleOnlyCallNeedsNothingOfALead(): void
    {
        $input = $this->payload();
        $input['identity']['lead_id'] = null;
        $input['lead'] = ['type' => null, 'source' => null, 'salesperson' => ['name' => 'Nobody, Atall']];

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::PEOPLE_ONLY, $result->outcome);
    }

    public function testParticipantsAndFollowersAreSkippedWithAWarningWhenThereIsNoLead(): void
    {
        $input = $this->payload();
        $input['identity']['lead_id'] = null;
        $input['participants'] = ['add' => [['firstname' => 'Marco', 'relationship_id' => '999999999']]];
        $input['followers'] = ['attach' => ['nobody.' . uniqid() . '@example.com']];

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::PEOPLE_ONLY, $result->outcome);
        $this->assertContains(['code' => 'lead_required', 'field' => 'participants'], $result->warnings);
        $this->assertContains(['code' => 'lead_required', 'field' => 'followers'], $result->warnings);
    }

    public function testAnOptOutIsHonoredEvenWhenThereIsNoLead(): void
    {
        $input = $this->payload();
        $input['identity']['lead_id'] = null;
        $input['customer']['consent'] = ['opt_out' => 'Y'];

        $result = $this->sync($input);

        $this->assertNull($result->lead);
        $this->assertTrue((bool) $result->people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
    }

    public function testThePeopleOnlyAnswerIsReplayedToo(): void
    {
        $input = $this->payload();
        $input['identity']['lead_id'] = null;

        $first = $this->sync($input);
        $replay = $this->sync($input);

        $this->assertTrue($replay->replayed);
        $this->assertNull($replay->lead);
        $this->assertSame($first->people->getId(), $replay->people->getId());
    }

    public function testALeadWhoseCustomerHasAnotherClientIdIsRefused(): void
    {
        $first = $this->sync($this->payload());

        $this->expectsError(
            CrmLeadSyncException::CLIENT_LEAD_MISMATCH,
            fn () => $this->sync($this->payload([
                'identity' => ['type' => 'reynolds', 'client_id' => '999' . random_int(1000, 9999), 'lead_id' => $this->identity($first)['lead_id']],
            ]))
        );
    }

    public function testAPersonWithAnotherClientIdIsNotTheCustomer(): void
    {
        $email = $this->uniqueEmail();
        $stranger = $this->createPeople('Ana', 'Perez', $email);
        $stranger->set(CustomFieldEnum::CLIENT_ID->value, 'someone-else');

        $result = $this->sync($this->payload(['customer' => ['emails' => [$email]]]));

        $this->assertSame(CrmLeadSyncOutcomeEnum::CREATED_ALL, $result->outcome);
        $this->assertNotSame($stranger->getId(), $result->people->getId());
    }

    /**
     * A lead whose people was deleted has nobody to open it for: the people is made again and the lead
     * pointed at it, not left attached to a row nothing can reach.
     */
    public function testALeadWhosePeopleIsGoneIsPointedAtTheNewPeople(): void
    {
        $first = $this->sync($this->payload());
        $first->people->softDelete();

        $result = $this->sync($this->payload(['identity' => $this->identity($first)]));

        $this->assertSame(CrmLeadSyncOutcomeEnum::OPENED, $result->outcome);
        $this->assertTrue($result->peopleCreated);
        $this->assertNotSame($first->people->getId(), $result->people->getId());
        $this->assertSame($result->people->getId(), (int) $first->lead->refresh()->people_id);
    }

    public function testAnUnresolvedSalespersonStopsTheCreateAndLeavesNothing(): void
    {
        $email = $this->uniqueEmail();
        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['lead']['salesperson'] = ['id' => 1, 'name' => 'Nobody, Atall ' . uniqid()];

        $this->expectsError(CrmLeadSyncException::OWNER_NOT_FOUND, fn () => $this->sync($input));

        $this->assertNothingWasCreated($email, $input['identity']['lead_id']);
    }

    /**
     * An owner that does not resolve only refuses a create: on a lead that already exists it is left as it is.
     */
    public function testAnUnresolvedSalespersonLeavesTheOwnerOfAnOpenedLead(): void
    {
        $first = $this->sync($this->payload());
        $owner = (int) $first->lead->leads_owner_id;

        $result = $this->sync([
            'request_id' => 'req-' . uniqid(),
            'identity' => $this->identity($first),
            'lead' => ['salesperson' => ['id' => 1, 'name' => 'Nobody, Atall ' . uniqid()]],
        ]);

        $this->assertSame(CrmLeadSyncOutcomeEnum::OPENED, $result->outcome);
        $this->assertSame($owner, (int) $result->lead->refresh()->leads_owner_id);
    }

    public function testAnUnresolvedSalespersonLeavesTheOwnerOfAnAdoptedLead(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $lead = $this->createLead($people, 'Active');
        $owner = (int) $lead->leads_owner_id;
        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['lead']['salesperson'] = ['id' => 1, 'name' => 'Nobody, Atall ' . uniqid()];

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::ADOPTED, $result->outcome);
        $this->assertSame($owner, (int) $lead->refresh()->leads_owner_id);
    }

    public function testAMissingTypeNameStopsTheCreate(): void
    {
        $email = $this->uniqueEmail();
        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['lead']['type'] = ['id' => 6, 'name' => null];

        $this->expectsError(CrmLeadSyncException::VALIDATION_FAILED, fn () => $this->sync($input), 'lead.type.name');

        $this->assertNothingWasCreated($email, $input['identity']['lead_id']);
    }

    public function testAMissingSourceNameStopsTheCreate(): void
    {
        $input = $this->payload();
        $input['lead']['source'] = null;

        $this->expectsError(CrmLeadSyncException::VALIDATION_FAILED, fn () => $this->sync($input), 'lead.source.name');
    }

    public function testNewPeopleNeedBothNames(): void
    {
        $input = $this->payload();
        $input['customer']['lastname'] = null;

        $this->expectsError(CrmLeadSyncException::VALIDATION_FAILED, fn () => $this->sync($input), 'customer.lastname');
    }

    public function testOpeningALeadNeedsNoneOfWhatACreateNeeds(): void
    {
        $first = $this->sync($this->payload());

        $result = $this->sync([
            'request_id' => 'req-' . uniqid(),
            'identity' => $this->identity($first),
        ]);

        $this->assertSame(CrmLeadSyncOutcomeEnum::OPENED, $result->outcome);
    }

    public function testNullsClearNothing(): void
    {
        $first = $this->sync($this->payload());
        $people = $first->people;
        $addressId = $people->getDefaultAddress()->getId();

        $this->sync([
            'request_id' => 'req-' . uniqid(),
            'identity' => $this->identity($first),
            'customer' => [
                'firstname' => null,
                'lastname' => null,
                'phones' => ['cell' => null, 'home' => null, 'work' => null],
                'emails' => [],
                'address' => null,
                'consent' => null,
            ],
            'lead' => ['type' => null, 'source' => null, 'status' => null, 'salesperson' => null],
        ]);

        $this->assertSame($addressId, $people->refresh()->getDefaultAddress()?->getId());
        $this->assertSame('7875550101', $people->getCellPhones()->first()->value);
        $this->assertCount(1, $people->getEmails());
        $this->assertSame('Walk-In', $first->lead->refresh()->type->name);
    }

    public function testTheDefaultAddressIsEditedInPlace(): void
    {
        $first = $this->sync($this->payload());
        $people = $first->people;
        $addressId = $people->getDefaultAddress()->getId();

        $this->sync([
            'request_id' => 'req-' . uniqid(),
            'identity' => $this->identity($first),
            'customer' => ['address' => ['address' => '9 New Street', 'city' => 'Ponce', 'state' => 'PR', 'zip' => '00716']],
        ]);

        $address = $people->refresh()->getDefaultAddress();
        $this->assertSame($addressId, $address->getId());
        $this->assertSame('9 New Street', $address->address);
        $this->assertSame('Ponce', $address->city);
        $this->assertGreaterThan(0, (int) $address->state_id, 'PR is a state of the United States');
        $this->assertCount(1, $people->address);
    }

    public function testAnAddressWithoutAStreetIsSkippedWithAWarning(): void
    {
        $input = $this->payload();
        $input['customer']['address'] = ['address' => '  ', 'city' => 'San Juan'];

        $result = $this->sync($input);

        $this->assertContains('address_incomplete', array_column($result->warnings, 'code'));
        $this->assertNull($result->people->getDefaultAddress());
    }

    public function testAnUnknownStateKeepsTheTextAndWarns(): void
    {
        $input = $this->payload();
        $input['customer']['address']['state'] = 'ZZ';

        $result = $this->sync($input);

        $this->assertContains('state_unmapped', array_column($result->warnings, 'code'));
        $this->assertSame('ZZ', $result->people->getDefaultAddress()->state);
    }

    public function testAPhoneAlreadyOnThePeopleUnderAnotherTypeIsNotAddedAgain(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $people->contacts()->create(['contacts_types_id' => 2, 'value' => '+1 (787) 555-0101', 'weight' => 0]);

        $this->sync($this->payload(['customer' => ['emails' => [$email]]]));

        $this->assertSame(
            1,
            $people->contacts()->whereRaw("RIGHT(REGEXP_REPLACE(value, '[^0-9]', ''), 10) = '7875550101'")->count()
        );
    }

    public function testParticipantsAreAddedAndRemoved(): void
    {
        $relationship = $this->createRelationship();
        $input = $this->payload();
        $input['participants'] = ['add' => [[
            'firstname' => 'Marco',
            'lastname' => 'Perez',
            'phone' => '7875550199',
            'email' => $this->uniqueEmail(),
            'relationship_id' => (string) $relationship->getId(),
        ]]];

        $first = $this->sync($input);

        $participant = LeadParticipant::query()->where('leads_id', $first->lead->getId())->first();
        $this->assertNotNull($participant);
        $this->assertSame((int) $relationship->getId(), (int) $participant->participants_types_id);

        $this->sync([
            'request_id' => 'req-' . uniqid(),
            'identity' => $this->identity($first),
            'participants' => ['remove' => [['people_id' => (string) $participant->peoples_id, 'relationship_id' => (string) $relationship->getId()]]],
        ]);

        $this->assertSame(0, LeadParticipant::query()->where('leads_id', $first->lead->getId())->count());
    }

    public function testRemovingSomeoneWhoIsNotAParticipantIsRefused(): void
    {
        $first = $this->sync($this->payload());
        $stranger = $this->createPeople('Not', 'Participant', $this->uniqueEmail());

        $this->expectsError(
            CrmLeadSyncException::INVALID_PARTICIPANT,
            fn () => $this->sync([
                'request_id' => 'req-' . uniqid(),
                'identity' => $this->identity($first),
                'participants' => ['remove' => [['people_id' => (string) $stranger->getId(), 'relationship_id' => '1']]],
            ])
        );
    }

    public function testARelationshipThatIsNotTheCompanysIsRefusedBeforeAnythingIsWritten(): void
    {
        $email = $this->uniqueEmail();
        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['participants'] = ['add' => [['firstname' => 'Marco', 'relationship_id' => '999999999']]];

        $this->expectsError(CrmLeadSyncException::INVALID_PARTICIPANT, fn () => $this->sync($input));

        $this->assertNothingWasCreated($email, $input['identity']['lead_id']);
    }

    public function testFollowersAreTheCompanysUsersNamedByEmail(): void
    {
        $user = auth()->user();
        $input = $this->payload();
        $input['followers'] = ['attach' => [strtoupper($user->email)]];

        $result = $this->sync($input);

        $this->assertTrue($user->isFollowing($result->lead));
    }

    public function testAnUnknownFollowerEmailIsRefusedBeforeAnythingIsWritten(): void
    {
        $email = $this->uniqueEmail();
        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['followers'] = ['attach' => ['nobody.' . uniqid() . '@example.com']];

        $this->expectsError(CrmLeadSyncException::FOLLOWER_NOT_FOUND, fn () => $this->sync($input), 'followers.attach');

        $this->assertNothingWasCreated($email, $input['identity']['lead_id']);
    }

    public function testACompanyNotConfiguredForTheCrmIsRefused(): void
    {
        $this->company()->del(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value);

        $this->expectsError(CrmLeadSyncException::CRM_NOT_CONFIGURED, fn () => $this->sync($this->payload()));
    }

    /**
     * The crm rows commit first and the custom fields follow, so a failure in the second half has to
     * take back what this request created.
     */
    public function testWhatTheRequestCreatedIsTakenBackWhenALaterStepFails(): void
    {
        $email = $this->uniqueEmail();
        $input = $this->payload(['customer' => ['emails' => [$email]]]);

        $driver = new class () extends ReynoldsCrmDriver {
            public function leadCustomFields(CrmLeadInfo $lead): array
            {
                throw new RuntimeException('boom');
            }
        };

        try {
            $this->sync($input, $driver);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertNothingWasCreated($email, $input['identity']['lead_id']);
        $this->assertNull(
            Lead::getByCustomFieldBuilderTransactionSafe(
                CustomFieldEnum::PROSPECT_ID->value,
                $input['identity']['lead_id'],
                $this->company()
            )->notDeleted()->first(),
            'the identity must not outlive the lead'
        );
    }

    public function testTheRealDriverIsUsedWhenNoneIsInjected(): void
    {
        $result = new SyncCrmLeadAction($this->dto($this->payload()))->execute();

        $this->assertInstanceOf(CrmLeadSyncResult::class, $result);
        $this->assertSame(CrmLeadSyncOutcomeEnum::CREATED_ALL, $result->outcome);
    }

    public function testTheConsentTheCrmSentIsStoredTheWayTheWebhookStoresIt(): void
    {
        $input = $this->payload();
        $input['customer']['consent'] = [
            'text_opt_status' => 'Opted In',
            'opt_out' => 'N',
            'opt_out_use' => 'y',
            'follow_up' => ['phone' => 'Y', 'mail' => 'N', 'email' => 'Y', 'text' => null],
        ];

        $people = $this->sync($input)->people;

        $this->assertSame('Y', $people->get(CustomFieldEnum::CONSENT_PHONE->value));
        $this->assertSame('N', $people->get(CustomFieldEnum::CONSENT_MAIL->value));
        $this->assertSame('Y', $people->get(CustomFieldEnum::CONSENT_EMAIL->value));
        $this->assertNull($people->get(CustomFieldEnum::CONSENT_TEXT->value), 'a value the CRM did not send is not written');
        $this->assertSame('N', $people->get(CustomFieldEnum::CONSENT_OPT_OUT->value));
        $this->assertSame('Y', $people->get(CustomFieldEnum::CONSENT_OPT_OUT_USE->value));
        $this->assertSame('Opted In', $people->get(CustomFieldEnum::TEXT_OPT_STATUS->value));
        $this->assertFalse((bool) $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
    }

    public function testNoConsentInTheRequestWritesNoConsent(): void
    {
        $people = $this->sync($this->payload())->people;

        $this->assertNull($people->get(CustomFieldEnum::CONSENT_OPT_OUT->value));
        $this->assertNull($people->get(CustomFieldEnum::TEXT_OPT_STATUS->value));
        $this->assertFalse((bool) $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
    }

    public function testAnOptOutFromTheCrmSilencesThePersonAndTheNewLead(): void
    {
        $input = $this->payload();
        $input['customer']['consent'] = ['opt_out' => 'Y'];

        $result = $this->sync($input);

        $this->assertTrue((bool) $result->people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
        $this->assertSame(
            'crm_sync:reynolds',
            $result->people->get(ConsentConfigurationEnum::DO_NOT_CONTACT_SOURCE->value)
        );
        $this->assertTrue(
            (bool) $result->lead->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value),
            'the lead exists when the opt-out runs, so it is flagged too'
        );
        $this->assertSame(
            0,
            $result->people->contacts()->where('is_opt_out', 0)->count(),
            'every contact is opted out'
        );
    }

    public function testAnOptOutFromTheCrmSilencesAnExistingPeopleToo(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $lead = $this->createLead($people, 'Active');
        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['customer']['consent'] = ['opt_out' => 'Y'];

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::ADOPTED, $result->outcome);
        $this->assertTrue((bool) $people->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
        $this->assertTrue((bool) $lead->refresh()->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
    }

    /**
     * The CRM can only silence: it never lifts an opt-out Kanvas holds, whatever it says later.
     */
    public function testALaterNoOptOutNeverLiftsAnOptOut(): void
    {
        $first = $this->payload();
        $first['customer']['consent'] = ['opt_out' => 'Y'];
        $result = $this->sync($first);

        $this->sync([
            'request_id' => 'req-' . uniqid(),
            'identity' => $this->identity($result),
            'customer' => ['consent' => ['opt_out' => 'N']],
        ]);

        $this->assertTrue((bool) $result->people->refresh()->get(ConsentConfigurationEnum::DO_NOT_CONTACT->value));
        $this->assertSame('N', $result->people->get(CustomFieldEnum::CONSENT_OPT_OUT->value), 'the CRM value is still recorded');
    }

    public function testALeadTheWebhookAlreadyCreatedIsOpenedAndItsPeopleGetsTheClientId(): void
    {
        $email = $this->uniqueEmail();
        $prospectId = (string) random_int(1000000, 9999999);
        $nameRecId = (string) random_int(100000, 999999);
        $webhookLead = $this->pullFromWebhook($prospectId, $nameRecId, $email);

        $input = $this->payload(['customer' => ['emails' => [$email]]]);
        $input['identity']['lead_id'] = $prospectId;

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::OPENED, $result->outcome);
        $this->assertSame($webhookLead->getId(), $result->lead->getId());
        $this->assertSame($webhookLead->people_id, $result->people->getId());
        $this->assertSame($input['identity']['client_id'], (string) $result->people->get(CustomFieldEnum::CLIENT_ID->value));
        $this->assertSame($nameRecId, (string) $result->people->get(CustomFieldEnum::NAME_REC_ID->value));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function onValues(): array
    {
        return ['true' => [true], 'one' => [1], 'string one' => ['1'], 'string true' => ['true']];
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function offValues(): array
    {
        return ['false' => [false], 'zero' => [0], 'string false' => ['false'], 'empty' => ['']];
    }

    #[DataProvider('onValues')]
    public function testTheChannelsAreOpenedWhenTheCustomFieldIsOn(mixed $value): void
    {
        $this->createSallyAgent();

        $result = $this->withChannelsSetting($value, fn () => $this->sync($this->payload()));

        $names = $result->lead->socialChannels()->pluck('name')->all();
        $this->assertContains('Sms ' . $result->lead->getId(), $names);
        $this->assertContains('Email ' . $result->lead->getId(), $names);
        $this->assertNotContains('social_channels_skipped', array_column($result->warnings, 'code'));
    }

    #[DataProvider('offValues')]
    public function testTheChannelsAreNotOpenedByTheSyncWhenTheCustomFieldIsOff(mixed $value): void
    {
        $this->createSallyAgent();

        $result = $this->withChannelsSetting($value, fn () => $this->sync($this->payload()));

        $names = $result->lead->socialChannels()->pluck('name')->all();
        $this->assertNotContains('Sms ' . $result->lead->getId(), $names);
        $this->assertNotContains('Email ' . $result->lead->getId(), $names);
    }

    public function testTheChannelsAreOpenedForAnAdoptedLeadToo(): void
    {
        $this->createSallyAgent();
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $lead = $this->createLead($people, 'Active');

        $result = $this->withChannelsSetting(true, fn () => $this->sync($this->payload(['customer' => ['emails' => [$email]]])));

        $this->assertSame(CrmLeadSyncOutcomeEnum::ADOPTED, $result->outcome);
        $names = $lead->refresh()->socialChannels()->pluck('name')->all();
        $this->assertContains('Sms ' . $lead->getId(), $names, 'the cell phone the CRM added opens an sms channel');
        $this->assertContains('Email ' . $lead->getId(), $names);
    }

    public function testWithoutAnAgentTheChannelsAreSkippedWithAWarningAndTheSyncStillSucceeds(): void
    {
        $result = $this->withChannelsSetting(true, fn () => $this->sync($this->payload()));

        $this->assertSame(CrmLeadSyncOutcomeEnum::CREATED_ALL, $result->outcome);
        $this->assertContains(['code' => 'social_channels_skipped', 'field' => 'agent'], $result->warnings);
    }

    public function testNoChannelsAreOpenedWhenThereIsNoLead(): void
    {
        $this->createSallyAgent();
        $input = $this->payload();
        $input['identity']['lead_id'] = null;

        $result = $this->withChannelsSetting(true, fn () => $this->sync($input));

        $this->assertSame(CrmLeadSyncOutcomeEnum::PEOPLE_ONLY, $result->outcome);
        $this->assertNotContains('social_channels_skipped', array_column($result->warnings, 'code'));
    }

    private function createSallyAgent(): void
    {
        $app = app(Apps::class);
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['name' => 'Crm Sync Test ' . uniqid()]);

        Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($this->company()->getId())
            ->create([
                'name' => 'Sally',
                'agent_type_id' => $agentType->getId(),
                'user_id' => auth()->user()->getId(),
            ]);
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withChannelsSetting(mixed $value, callable $callback): mixed
    {
        $company = $this->company();
        $setting = CrmLeadSyncSettingEnum::CREATE_SOCIAL_CHANNELS->value;
        $company->set($setting, $value);

        try {
            return $callback();
        } finally {
            $company->del($setting);
        }
    }

    private function sync(array $input, ?ReynoldsCrmDriver $driver = null): CrmLeadSyncResult
    {
        return new SyncCrmLeadAction($this->dto($input), $driver)->execute();
    }

    private function pullFromWebhook(string $prospectId, string $nameRecId, string $email): Lead
    {
        $user = auth()->user();

        return new PullLeadAction(app(Apps::class), $user->getCurrentCompany(), $user)->execute([
            'Identifier' => ['ProspectId' => $prospectId],
            'Prospect' => ['IsAiGenerated' => 'N'],
            'IndividualCustomer' => ['FirstName' => 'Ana', 'LastName' => 'Perez', 'NameRecId' => $nameRecId],
            'Email' => ['MailTo' => $email],
        ]);
    }

    private function dto(array $input): CrmLeadSync
    {
        $user = auth()->user();

        return CrmLeadSync::from(
            app(Apps::class),
            $user,
            $user->getCurrentCompany(),
            $input
        );
    }

    private function identityValue(string $key): string
    {
        return $this->lastIdentity[$key];
    }

    private function payload(array $overrides = []): array
    {
        $user = auth()->user();
        $identity = [
            'type' => 'reynolds',
            'client_id' => (string) random_int(1000000, 9999999),
            'lead_id' => (string) random_int(1000000, 9999999),
        ];
        $this->lastIdentity = $identity;

        $payload = [
            'request_id' => 'req-' . uniqid(),
            'identity' => $identity,
            'customer' => [
                'firstname' => 'Ana',
                'lastname' => 'Perez',
                'language' => 'es',
                'phones' => ['cell' => '7875550101', 'home' => null, 'work' => '7875550103'],
                'emails' => [$this->uniqueEmail()],
                'address' => ['address' => '123 Calle Sol', 'city' => 'San Juan', 'state' => 'PR', 'zip' => '00901'],
            ],
            'lead' => [
                'type' => ['id' => 6, 'name' => 'Walk-In'],
                'source' => ['id' => 102623, 'name' => '*Drove By'],
                'status' => 'Open',
                'salesperson' => ['id' => 469364, 'name' => $user->lastname . ', ' . $user->firstname],
            ],
        ];

        foreach ($overrides as $section => $values) {
            $payload[$section] = array_merge($payload[$section] ?? [], $values);
        }

        return $payload;
    }

    private function identity(CrmLeadSyncResult $result): array
    {
        return [
            'type' => 'reynolds',
            'client_id' => (string) $result->people->get(CustomFieldEnum::CLIENT_ID->value),
            'lead_id' => (string) $result->lead->get(CustomFieldEnum::PROSPECT_ID->value),
        ];
    }

    private function expectsError(string $reason, callable $callback, ?string $field = null): void
    {
        try {
            $callback();
            $this->fail('expected the error ' . $reason);
        } catch (CrmLeadSyncException $exception) {
            $this->assertSame($reason, $exception->getExtensions()['reason']);

            if ($field !== null) {
                $this->assertSame($field, $exception->getExtensions()['field']);
            }
        }
    }

    private function assertNothingWasCreated(string $email, string $leadId): void
    {
        $this->assertSame(
            0,
            People::query()
                ->whereHas('contacts', fn ($query) => $query->where('value', $email))
                ->count(),
            'no people may be left behind'
        );
        $this->assertNull(
            Lead::getByCustomFieldBuilderTransactionSafe(CustomFieldEnum::PROSPECT_ID->value, $leadId, $this->company())
                ->notDeleted()
                ->first()
        );
    }

    private function company(): CompanyInterface
    {
        return Companies::getById(auth()->user()->getCurrentCompany()->getId());
    }

    private function uniqueEmail(): string
    {
        return 'crm.' . uniqid() . '@example.com';
    }

    private function createPeople(string $firstname, string $lastname, string $email): People
    {
        $user = auth()->user();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['firstname' => $firstname, 'lastname' => $lastname]);
        $people->contacts()->create(['contacts_types_id' => 1, 'value' => $email, 'weight' => 0]);

        return $people;
    }

    private function createLead(People $people, string $statusName): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $statusId = DB::connection('crm')->table('leads_status')->insertGetId([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => $statusName,
            'is_default' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create(['leads_status_id' => $statusId]);
    }

    private function createRelationship(): PeopleRelationship
    {
        return PeopleRelationship::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'name' => 'Co-buyer ' . uniqid(),
        ]);
    }
}
