<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Jobs\ReindexPeopleSearchJob;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

/**
 * A lead used to be found by title, name, email and description only: its phone was never in
 * query_by, and the contact-phone fallback was dead code behind a `(string)` cast. Every phone and
 * email the lead or its person carries must reach the index.
 */
final class LeadSearchablePhonesTest extends TestCase
{
    use DatabaseTransactions;

    private const LINKEDIN_CONTACT_TYPE_ID = 5;

    protected $connectionsToTransact = [null, 'crm'];

    public function testLeadDocumentCarriesEveryPhoneAndEmailOfTheLeadAndItsPerson(): void
    {
        Queue::fake();
        $people = $this->people();
        $this->addContact($people, ContactTypeEnum::CELLPHONE, '18095551234');
        $this->addContact($people, ContactTypeEnum::WORK_PHONE, '8295550000');
        $this->addContact($people, ContactTypeEnum::EMAIL, 'Main@Acme.test');

        $lead = $this->lead($people, ['phone' => '(849) 555-7777', 'email' => '']);

        $doc = $lead->fresh()->toSearchableArray();

        $this->assertEqualsCanonicalizing(
            ['8495557777', '18095551234', '8095551234', '8295550000'],
            $doc['phones']
        );
        $this->assertSame(['main@acme.test'], $doc['emails']);
        $this->assertSame('8495557777', $doc['phone']);
        $this->assertSame('Main@Acme.test', $doc['email'], 'a lead without its own email falls back to the person\'s');
    }

    public function testLeadWithoutItsOwnPhoneFallsBackToThePersonsPhone(): void
    {
        Queue::fake();
        $people = $this->people();
        $this->addContact($people, ContactTypeEnum::PHONE, '8095551234');

        $doc = $this->lead($people, ['phone' => null])->fresh()->toSearchableArray();

        $this->assertSame('8095551234', $doc['phone']);
        $this->assertSame(['8095551234'], $doc['phones']);
    }

    public function testPeopleDocumentCarriesEveryPhone(): void
    {
        Queue::fake();
        $people = $this->people();
        $this->addContact($people, ContactTypeEnum::CELLPHONE, '8095551234');
        $this->addContact($people, ContactTypeEnum::PHONE, '8295550000');

        $doc = $people->fresh()->toSearchableArray();

        $this->assertEqualsCanonicalizing(['8095551234', '8295550000'], $doc['phones']);
    }

    public function testQueryBySearchesThePhoneAndEmailArraysOnceTheCollectionHasThem(): void
    {
        $lead = new Lead();
        $schema = $lead->typesenseCollectionSchema();
        Cache::put($this->schemaCacheKey($lead), $schema, 60);

        $queryBy = explode(',', $lead->searchQueryBy());

        $this->assertContains('phones', $queryBy);
        $this->assertContains('emails', $queryBy);
    }

    /**
     * A query_by field the live collection lacks 404s every search, so until the schema sync adds
     * them the arrays stay out.
     */
    public function testQueryBySkipsThePhoneAndEmailArraysBeforeTheCollectionIsSynced(): void
    {
        $lead = new Lead();
        $schema = $lead->typesenseCollectionSchema();
        $schema['fields'] = array_values(array_filter(
            $schema['fields'],
            fn (array $field) => ! in_array($field['name'], ['phones', 'emails'], true)
        ));
        Cache::put($this->schemaCacheKey($lead), $schema, 60);

        $queryBy = explode(',', $lead->searchQueryBy());

        $this->assertNotContains('phones', $queryBy);
        $this->assertContains('title', $queryBy);
    }

    public function testSavingAPhoneContactReindexesThePerson(): void
    {
        Queue::fake();
        $people = $this->people();

        $contact = $this->addContact($people, ContactTypeEnum::CELLPHONE, '8095551234');

        Queue::assertPushed(
            ReindexPeopleSearchJob::class,
            fn (ReindexPeopleSearchJob $job) => $job->people->getId() === $people->getId()
        );

        Queue::fake();
        $contact->value = '8095559999';
        $contact->saveOrFail();

        Queue::assertPushed(ReindexPeopleSearchJob::class);
    }

    public function testSavingANonPhoneContactDoesNotReindex(): void
    {
        Queue::fake();
        $people = $this->people();

        $people->contacts()->create([
            'contacts_types_id' => self::LINKEDIN_CONTACT_TYPE_ID,
            'value' => 'https://linkedin.com/in/acme',
            'weight' => 1,
        ]);

        Queue::assertNotPushed(ReindexPeopleSearchJob::class);
    }

    protected function tearDown(): void
    {
        Cache::forget($this->schemaCacheKey(new Lead()));

        parent::tearDown();
    }

    private function schemaCacheKey(Lead $lead): string
    {
        return 'typesense_collection_schema_' . app(Apps::class)->getId() . '_' . $lead->searchableAs();
    }

    private function people(): People
    {
        $user = auth()->user();

        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Phone', 'lastname' => 'Search']);
    }

    private function addContact(People $people, ContactTypeEnum $type, string $value): Contact
    {
        return $people->contacts()->create([
            'contacts_types_id' => $type->value,
            'value' => $value,
            'weight' => 1,
        ]);
    }

    private function lead(People $people, array $attributes): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($people->companies_id)
            ->withPeopleId($people->getId())
            ->create($attributes);
    }
}
