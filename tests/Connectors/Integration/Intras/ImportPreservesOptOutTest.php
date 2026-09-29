<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\PullParticipantsFromIntrasAction;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

/**
 * Re-importing a participant must never re-enable outreach to someone who opted out.
 *
 * An opt-out writes `is_opt_out = 1` on **every** Contact the person has
 * (`Guild/Customers/CLAUDE.md`), and the importer re-attaches the same addresses SIPGO holds on
 * every run. `Contact::updateOrCreate()` applies its second argument on update as well as create,
 * so naming `is_opt_out` there silently reset consent for every matched contact — and the import
 * is now idempotent and documented as safe to re-run, which is exactly what made that reachable.
 *
 * The column defaults to 0, so leaving it out still lands a genuinely new contact opted in.
 */
class ImportPreservesOptOutTest extends TestCase
{
    private Apps $kanvasApp;
    private Companies $company;
    private People $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        $this->person = new People();
        $this->person->apps_id = $this->kanvasApp->getId();
        $this->person->companies_id = $this->company->getId();
        $this->person->users_id = static::$cachedUser->getId();
        $this->person->firstname = 'OptOut';
        $this->person->lastname = 'Probe';
        $this->person->name = 'OptOut Probe';
        $this->person->saveOrFail();
    }

    protected function tearDown(): void
    {
        Contact::where('peoples_id', $this->person->getId())->forceDelete();
        // The base TestCase has no DatabaseTransactions, so these rows commit — and an
        // uncleaned person is flattened into rpt_ejecutivo by the next rebuild.
        $this->person->forceDelete();

        parent::tearDown();
    }

    public function testReimportingDoesNotResetAnOptedOutContact(): void
    {
        $contacts = [[
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'opted.out@example.com',
            'weight' => 1,
        ]];

        PullParticipantsFromIntrasAction::attachContactsToPeople($this->person, $contacts);

        $contact = Contact::where('peoples_id', $this->person->getId())->firstOrFail();
        $this->assertSame(0, (int) $contact->is_opt_out, 'a new contact lands opted in');

        // The person opts out; every contact they hold is flagged.
        $contact->update(['is_opt_out' => 1]);

        // SIPGO still holds the same address, so the next import re-attaches it.
        PullParticipantsFromIntrasAction::attachContactsToPeople($this->person, $contacts);

        $this->assertSame(
            1,
            (int) $contact->fresh()->is_opt_out,
            'the import must not re-enable outreach to someone who opted out'
        );
    }

    public function testTheContactIsNotDuplicatedOnReimport(): void
    {
        $contacts = [[
            'type' => ContactTypeEnum::EMAIL,
            'value' => 'stable@example.com',
            'weight' => 1,
        ]];

        PullParticipantsFromIntrasAction::attachContactsToPeople($this->person, $contacts);
        PullParticipantsFromIntrasAction::attachContactsToPeople($this->person, $contacts);

        $this->assertSame(1, Contact::where('peoples_id', $this->person->getId())->count());
    }

    /**
     * The weight still tracks SIPGO — only consent is off limits.
     */
    public function testWeightIsStillUpdatedFromTheSource(): void
    {
        $type = ContactTypeEnum::EMAIL;

        PullParticipantsFromIntrasAction::attachContactsToPeople(
            $this->person,
            [['type' => $type, 'value' => 'weighted@example.com', 'weight' => 1]]
        );
        PullParticipantsFromIntrasAction::attachContactsToPeople(
            $this->person,
            [['type' => $type, 'value' => 'weighted@example.com', 'weight' => 5]]
        );

        $this->assertSame(5, (int) Contact::where('peoples_id', $this->person->getId())->firstOrFail()->weight);
    }
}
