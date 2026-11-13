<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssist\SyncCrmLead;

use Baka\Contracts\CompanyInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Actions\SyncCrmLeadAction;
use Kanvas\Connectors\SalesAssist\DataTransferObject\CrmLeadSync;
use Kanvas\Connectors\SalesAssist\DataTransferObject\CrmLeadSyncResult;
use Kanvas\Connectors\SalesAssist\Enums\CrmLeadSyncOutcomeEnum;
use Kanvas\Connectors\SalesAssist\Exceptions\CrmLeadSyncException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleRelationship;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * eLead only links: it opens or adopts what it finds and never creates.
 *
 * Serial: it sets and restores a company setting, which is Redis-backed and shared by every parallel process.
 */
#[Group('serial')]
final class EleadSyncCrmLeadTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem', 'intelligence', 'social'];

    private mixed $previousSubscription = null;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->previousSubscription = $this->company()->get(CustomFieldEnum::COMPANY->value);
        $this->company()->set(CustomFieldEnum::COMPANY->value, 'TEST-SUBSCRIPTION');
    }

    protected function tearDown(): void
    {
        $key = CustomFieldEnum::COMPANY->value;
        $this->previousSubscription === null
            ? $this->company()->del($key)
            : $this->company()->set($key, $this->previousSubscription);

        parent::tearDown();
    }

    public function testALeadWithTheLeadIdIsOpened(): void
    {
        $people = $this->createPeople('Ana', 'Perez', $this->uniqueEmail());
        $lead = $this->createLead($people, 'Active');
        $input = $this->payload($this->uniqueEmail());
        $lead->set(CustomFieldEnum::LEAD_ID->value, $input['identity']['lead_id']);

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::OPENED, $result->outcome);
        $this->assertSame($lead->getId(), $result->lead->getId());
        $this->assertSame($people->getId(), $result->people->getId());
        $this->assertFalse($result->peopleCreated);
        $this->assertFalse($result->leadCreated);
    }

    public function testAnActiveLeadOfAMatchedPeopleIsAdoptedAndGetsTheIds(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $lead = $this->createLead($people, 'Active');
        $input = $this->payload($email);
        $peopleBefore = $this->peopleCount();

        $result = $this->sync($input);

        $this->assertSame(CrmLeadSyncOutcomeEnum::ADOPTED, $result->outcome);
        $this->assertSame($lead->getId(), $result->lead->getId());
        $this->assertFalse($result->peopleCreated);
        $this->assertFalse($result->leadCreated);
        $this->assertSame($input['identity']['lead_id'], (string) $lead->refresh()->get(CustomFieldEnum::LEAD_ID->value));
        $this->assertSame($input['identity']['client_id'], (string) $people->get(CustomFieldEnum::PERSON_ID->value));
        $this->assertSame($peopleBefore, $this->peopleCount());
    }

    public function testNothingMatchedIsNotFoundAndWritesNothing(): void
    {
        $input = $this->payload($this->uniqueEmail());
        $peopleBefore = $this->peopleCount();
        $leadsBefore = $this->leadCount();

        $this->expectsError(CrmLeadSyncException::NOT_FOUND, 'identity', fn () => $this->sync($input));

        $this->assertSame($peopleBefore, $this->peopleCount());
        $this->assertSame($leadsBefore, $this->leadCount());
        $this->assertNull(
            Lead::getByCustomFieldBuilderTransactionSafe(CustomFieldEnum::LEAD_ID->value, $input['identity']['lead_id'], $this->company())->first()
        );
        $this->assertNull(
            People::getByCustomFieldBuilderTransactionSafe(CustomFieldEnum::PERSON_ID->value, $input['identity']['client_id'], $this->company())->first()
        );
    }

    public function testAMatchedPeopleWithoutAnActiveLeadIsPeopleOnly(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $leadsBefore = $this->leadCount();

        $result = $this->sync($this->payload($email));

        $this->assertSame(CrmLeadSyncOutcomeEnum::PEOPLE_ONLY, $result->outcome);
        $this->assertSame($people->getId(), $result->people->getId());
        $this->assertNull($result->lead);
        $this->assertFalse($result->leadCreated);
        $this->assertContains(['code' => 'lead_not_found', 'field' => 'identity.lead_id'], $result->warnings);
        $this->assertSame($leadsBefore, $this->leadCount());
    }

    public function testAParticipantThatMatchesNobodyIsSkippedWithAWarning(): void
    {
        $email = $this->uniqueEmail();
        $people = $this->createPeople('Ana', 'Perez', $email);
        $lead = $this->createLead($people, 'Active');
        $knownEmail = $this->uniqueEmail();
        $known = $this->createPeople('Marco', 'Perez', $knownEmail);
        $unknownEmail = $this->uniqueEmail();
        $relationship = $this->createRelationship();
        $input = $this->payload($email);
        $input['participants'] = ['add' => [
            ['firstname' => 'Marco', 'lastname' => 'Perez', 'email' => $knownEmail, 'relationship_id' => (string) $relationship->getId()],
            ['firstname' => 'Luis', 'lastname' => 'Perez', 'email' => $unknownEmail, 'relationship_id' => (string) $relationship->getId()],
        ]];

        $result = $this->sync($input);

        $this->assertContains(['code' => 'participant_not_found', 'field' => 'participants.add.1'], $result->warnings);
        $participants = LeadParticipant::query()->where('leads_id', $lead->getId())->pluck('peoples_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([(int) $known->getId()], $participants);
        $this->assertSame(
            0,
            People::query()->whereHas('contacts', fn ($query) => $query->where('value', $unknownEmail))->count()
        );
    }

    public function testACompanyWithoutAnEleadSubscriptionIsRefused(): void
    {
        $this->company()->del(CustomFieldEnum::COMPANY->value);

        $this->expectsError(
            CrmLeadSyncException::CRM_NOT_CONFIGURED,
            null,
            fn () => $this->sync($this->payload($this->uniqueEmail()))
        );
    }

    private function sync(array $input): CrmLeadSyncResult
    {
        $user = auth()->user();

        return new SyncCrmLeadAction(
            CrmLeadSync::from(
                app(Apps::class),
                $user,
                $user->getCurrentCompany(),
                $input
            )
        )->execute();
    }

    /**
     * What the eLead lead page gives: the popup ids, the name and contacts, nothing about the lead.
     */
    private function payload(string $email): array
    {
        return [
            'request_id' => 'req-' . uniqid(),
            'identity' => [
                'type' => 'elead',
                'client_id' => (string) random_int(1000000, 9999999),
                'lead_id' => (string) random_int(1000000, 9999999),
            ],
            'customer' => [
                'firstname' => 'Ana',
                'lastname' => 'Perez',
                'emails' => [$email],
            ],
        ];
    }

    private function expectsError(string $reason, ?string $field, callable $callback): void
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

    private function peopleCount(): int
    {
        return People::query()->where('companies_id', $this->company()->getId())->count();
    }

    private function leadCount(): int
    {
        return Lead::query()->where('companies_id', $this->company()->getId())->count();
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
