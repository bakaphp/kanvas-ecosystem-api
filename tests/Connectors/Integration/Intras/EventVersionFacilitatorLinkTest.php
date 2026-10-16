<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\PullEventVersionFacilitatorsFromIntrasAction;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

/**
 * `event_version_facilitators` was never populated — only People rows were created for
 * facilitators — so no event version had one and every facilitator-by-event filter on the Gestor
 * matched nothing.
 *
 * These cover the Kanvas-side prerequisites the link import depends on. The legacy read itself
 * needs a real SIPGO database, which the suite has no access to.
 */
class EventVersionFacilitatorLinkTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'event'];

    private Apps $kanvasApp;
    private Companies $kanvasCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->kanvasCompany = static::$cachedUser->getCurrentCompany();
    }

    /**
     * `facilitators.slug` is NOT NULL with no default and the model carries no SlugTrait, so the
     * importer has to set it. Without this the very first production import dies on insert.
     */
    public function test_a_facilitator_row_can_be_created_with_the_slug_the_importer_sets(): void
    {
        $facilitator = $this->seedFacilitator();

        $this->assertNotEmpty($facilitator->slug);
        $this->assertNotNull($facilitator->people);
        $this->assertSame('Facilitator', $facilitator->people->firstname);
    }

    /**
     * The link import resolves both sides through their legacy-id custom fields, so the
     * Facilitator row must carry INTRAS_FACILITATOR_ID or it can never be matched back.
     */
    public function test_the_facilitator_row_is_findable_by_its_legacy_id(): void
    {
        $legacyId = random_int(600000, 699999);
        $facilitator = $this->seedFacilitator();
        $facilitator->set(CustomFieldEnum::INTRAS_FACILITATOR_ID->value, $legacyId);

        $map = $this->action()->facilitatorMap();

        $this->assertArrayHasKey($legacyId, $map);
        $this->assertSame($facilitator->getId(), $map[$legacyId]);
    }

    /**
     * A link whose version or facilitator never imported is skipped rather than written
     * half-resolved — the GraphQL relation the Facilitadores tab reads is non-null, and an orphan
     * row fatals the resolver.
     */
    public function test_the_import_is_a_noop_when_neither_side_has_been_imported(): void
    {
        $otherCompany = Companies::factory()->create([
            'users_id' => static::$cachedUser->getId(),
        ]);

        $this->assertSame(
            0,
            $this->action($otherCompany)->execute(),
            'with no legacy ids mapped for this company the import must write nothing'
        );
    }

    private function action(?Companies $company = null): object
    {
        return new class (
            $this->kanvasApp,
            $company ?? $this->kanvasCompany,
            static::$cachedUser,
        ) extends PullEventVersionFacilitatorsFromIntrasAction {
            /** @return array<int, int> */
            public function facilitatorMap(): array
            {
                return $this->legacyIdMap(
                    Facilitator::class,
                    CustomFieldEnum::INTRAS_FACILITATOR_ID->value
                );
            }
        };
    }

    private function seedFacilitator(): Facilitator
    {
        $people = new People();
        $people->apps_id = $this->kanvasApp->getId();
        $people->companies_id = $this->kanvasCompany->getId();
        $people->users_id = static::$cachedUser->getId();
        $people->firstname = 'Facilitator';
        $people->lastname = 'Link ' . uniqid();
        $people->name = $people->firstname . ' ' . $people->lastname;
        // saveQuietly() skips UuidTrait's creating hook, and the column is NOT NULL with no
        // default — a local database that has drifted a default hides this until CI.
        $people->generateUuidIfMissing()->saveQuietly();

        /** @var Facilitator $facilitator */
        $facilitator = Facilitator::firstOrCreate([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->kanvasCompany->getId(),
            'people_id' => $people->getId(),
        ], [
            'users_id' => static::$cachedUser->getId(),
            'slug' => Str::slug($people->name . '-' . $people->getId()),
        ]);

        return $facilitator;
    }
}
