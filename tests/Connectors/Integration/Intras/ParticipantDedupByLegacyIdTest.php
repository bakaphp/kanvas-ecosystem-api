<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\PullParticipantsFromIntrasAction;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use stdClass;
use Tests\TestCase;

/**
 * Participants are matched on the SIPGO id, not firstname + lastname.
 *
 * The old key merged distinct same-name participants into one People row and kept only the
 * last-imported legacy id — erasing exactly the duplicates the duplicate-profile report exists to
 * find. The key is scoped to the company because agency scope is derived ("registered for an event
 * in this agency"), so one participant active in two agencies is legitimately two People rows.
 */
class ParticipantDedupByLegacyIdTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $kanvasApp;
    private Companies $kanvasCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->kanvasCompany = static::$cachedUser->getCurrentCompany();
    }

    public function test_two_participants_sharing_a_name_stay_two_people(): void
    {
        $suffix = uniqid();
        $legacyA = random_int(900000, 949999);
        $legacyB = random_int(950000, 999999);

        $action = $this->action();

        $first = $action->resolve($this->row($legacyA, 'Laura', 'Hache ' . $suffix));
        $first->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, $legacyA);

        // Fresh action: a second import run rebuilds the map from what the first run wrote.
        $second = $this->action()->resolve($this->row($legacyB, 'Laura', 'Hache ' . $suffix));

        $this->assertNotSame(
            $first->getId(),
            $second->getId(),
            'same-name participants with different SIPGO ids must not collapse into one People row'
        );
    }

    public function test_reimporting_the_same_legacy_id_reuses_the_existing_person(): void
    {
        $legacyId = random_int(800000, 899999);
        $name = 'Reimport ' . uniqid();

        $created = $this->action()->resolve($this->row($legacyId, 'Stephanie', $name));
        $created->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, $legacyId);

        $again = $this->action()->resolve($this->row($legacyId, 'Stephanie', $name));

        $this->assertSame(
            $created->getId(),
            $again->getId(),
            'a second import run must update the existing person, not duplicate them'
        );
    }

    /**
     * People carries a global `is_deleted = 0` scope, so dropping `notDeleted()` from the lookup
     * was never enough — the map found the id and the query still returned null, and every run
     * added another copy. Agency 4 reached 74 People rows for 24 soft-deleted participants.
     */
    public function test_reimporting_a_soft_deleted_participant_reuses_the_existing_person(): void
    {
        $legacyId = random_int(600000, 699999);
        $name = 'Borrado ' . uniqid();

        $created = $this->action()->resolve($this->row($legacyId, 'Ivette', $name, isDeleted: true));
        $created->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, $legacyId);

        $this->assertSame(1, (int) $created->is_deleted, 'the person must be imported flagged deleted');

        $again = $this->action()->resolve($this->row($legacyId, 'Ivette', $name, isDeleted: true));

        $this->assertSame(
            $created->getId(),
            $again->getId(),
            'a participant deleted in SIPGO must be found again, not duplicated on every run'
        );
    }

    /**
     * The importer writes with `saveQuietly()` to keep tens of thousands of people off the Scout
     * index, which also skips `UuidTrait`'s `creating` hook. 36,933 imported People landed with a
     * null uuid before anyone noticed, because nothing reads it until something else does.
     */
    public function test_an_imported_participant_gets_a_uuid_despite_the_quiet_save(): void
    {
        $legacyId = random_int(500000, 599999);

        $person = $this->action()->resolve($this->row($legacyId, 'Uuid', 'Target ' . uniqid()));

        $this->assertNotEmpty($person->uuid, 'saveQuietly() skips the creating hook that sets the uuid');
        $this->assertSame(
            $person->uuid,
            People::withTrashed()->where('id', $person->getId())->first()->uuid,
            'the uuid must be persisted, not just set in memory'
        );
    }

    public function test_the_legacy_id_map_is_scoped_to_the_company(): void
    {
        $legacyId = random_int(700000, 799999);
        $name = 'Agency ' . uniqid();

        $person = $this->action()->resolve($this->row($legacyId, 'Raquel', $name));
        $person->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, $legacyId);

        // A different company (agency) importing the same SIPGO participant must not find this row.
        $otherCompany = Companies::factory()->create([
            'users_id' => static::$cachedUser->getId(),
        ]);

        $map = $this->action($otherCompany)->idMap();

        $this->assertArrayNotHasKey(
            $legacyId,
            $map,
            'a legacy id imported into one agency must not resolve inside another'
        );
    }

    private function action(?Companies $company = null): object
    {
        return new class (
            $this->kanvasApp,
            $company ?? $this->kanvasCompany,
            static::$cachedUser,
        ) extends PullParticipantsFromIntrasAction {
            public function resolve(stdClass $row): People
            {
                $this->preloadParticipantIdMap();

                return $this->resolvePeople($row, [
                    'firstname' => trim($row->first_name),
                    'lastname' => trim($row->last_name),
                ], null);
            }

            /** @return array<int, int> */
            public function idMap(): array
            {
                $this->preloadParticipantIdMap();

                return $this->participantIdMap;
            }
        };
    }

    private function row(
        int $id,
        string $firstName,
        string $lastName,
        bool $isDeleted = false
    ): stdClass {
        $row = new stdClass();
        $row->id = $id;
        $row->first_name = $firstName;
        $row->last_name = $lastName;
        $row->created_at = null;
        $row->is_deleted = (int) $isDeleted;

        return $row;
    }
}
