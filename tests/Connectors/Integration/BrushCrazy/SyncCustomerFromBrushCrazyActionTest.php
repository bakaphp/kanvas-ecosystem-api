<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCustomerFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleType;
use Tests\TestCase;

final class SyncCustomerFromBrushCrazyActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    private int $bcUserId;

    protected function setUp(): void
    {
        parent::setUp();

        // Custom fields also land in Redis, which never rolls back — a fresh source id per test
        // keeps one run from resolving another run's People.
        $this->bcUserId = random_int(10_000_000, 99_999_999);

        $company = auth()->user()->getCurrentCompany();
        $type = PeopleType::firstOrCreate(
            [
                'name' => 'Customer',
                'apps_id' => app(Apps::class)->getId(),
                'companies_id' => $company->getId(),
            ],
            ['users_id' => auth()->user()->getId()],
        );
        $type->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, 'people-type:customer');
    }

    public function testCreatesThePeopleWithContactsAddressAndExternalId(): void
    {
        $people = $this->action()->fromRow($this->row());

        $this->assertSame('Jane', $people->firstname);
        $this->assertSame('Doe', $people->lastname);
        $this->assertNotEmpty($people->uuid);
        $this->assertSame('2020-05-01', $people->created_at->format('Y-m-d'));
        $this->assertSame($this->bcUserId, (int) $people->get(CustomFieldEnum::BRUSHCRAZY_USER_ID->value));
        $this->assertSame('jane@example.com', $people->getEmails()->first()?->value);
        $this->assertSame('6145550100', $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::PHONE))->value('value'));
        $this->assertSame('123 Main St', $people->getDefaultAddress()?->address);
    }

    public function testReSyncUpdatesTheSameRowWithoutDuplicatingContacts(): void
    {
        $first = $this->action()->fromRow($this->row());
        $second = $this->action()->fromRow($this->row(['name' => 'Jane Roe']));

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame('Roe', $second->fresh()->lastname);
        $this->assertSame(1, $second->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::EMAIL))->count());
    }

    /** An opt-out recorded in Kanvas is consent, and a re-sync must never switch it back on. */
    public function testReSyncKeepsAKanvasOptOut(): void
    {
        $people = $this->action()->fromRow($this->row());
        $people->contacts()->update(['is_opt_out' => 1]);

        $this->action()->fromRow($this->row());

        $this->assertSame(0, $people->contacts()->where('is_opt_out', 0)->count());
    }

    public function testSoftDeletedUserIsFlaggedAndFoundAgainOnTheNextRun(): void
    {
        $deleted = $this->action()->fromRow($this->row(['deleted_at' => '2025-01-01 00:00:00']));
        $again = $this->action()->fromRow($this->row(['deleted_at' => '2025-01-01 00:00:00']));

        $this->assertTrue((bool) People::withTrashed()->find($deleted->getId())->is_deleted);
        $this->assertSame($deleted->getId(), $again->getId());
    }

    /** The site created the buyer first: the mirror stamps that People instead of making a twin. */
    public function testAdoptsAPeopleTheSiteCreatedWithTheSameEmail(): void
    {
        $native = $this->nativePeople('jane@example.com');

        $synced = $this->action()->fromRow($this->row());

        $this->assertSame($native->getId(), $synced->getId());
        $this->assertSame($this->bcUserId, (int) $synced->get(CustomFieldEnum::BRUSHCRAZY_USER_ID->value));
        $this->assertSame('Doe', $synced->fresh()->lastname);
        $this->assertSame(1, $synced->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::EMAIL))->count());
    }

    public function testAdoptionThroughAPreloadedChunkFindsTheSamePeople(): void
    {
        $native = $this->nativePeople('jane@example.com');
        $action = $this->action();

        $action->preloadRows([$this->row()]);

        $this->assertSame($native->getId(), $action->fromRow($this->row())->getId());
    }

    public function testDoesNotAdoptAPeopleAlreadyLinkedToAnotherBrushCrazyUser(): void
    {
        $other = $this->action()->fromRow($this->row(['id' => $this->bcUserId + 1]));

        $synced = $this->action()->fromRow($this->row());

        $this->assertNotSame($other->getId(), $synced->getId());
    }

    /** A user deleted at the source must not hide the site's live customer by flagging it. */
    public function testASourceDeletedUserDoesNotAdopt(): void
    {
        $native = $this->nativePeople('jane@example.com');

        $synced = $this->action()->fromRow($this->row(['deleted_at' => '2025-01-01 00:00:00']));

        $this->assertNotSame($native->getId(), $synced->getId());
        $this->assertFalse((bool) People::withTrashed()->find($native->getId())->is_deleted);
    }

    public function testSeveralCandidatesAdoptTheOldest(): void
    {
        $oldest = $this->nativePeople('jane@example.com');
        $this->nativePeople('jane@example.com');

        $this->assertSame($oldest->getId(), $this->action()->fromRow($this->row())->getId());
    }

    /** An email the source cleared and later set again comes back, not left deleted. */
    public function testAContactTheMirrorRemovedIsRestoredWhenTheSourceValueReturns(): void
    {
        $people = $this->action()->fromRow($this->row());
        $this->action()->fromRow($this->row(['email' => null]));
        $this->assertSame(0, $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::EMAIL))->count());

        $this->action()->fromRow($this->row());

        $this->assertSame('jane@example.com', $people->getEmails()->first()?->value);
    }

    /** One removed in Kanvas is consent-like: the mirror does not bring it back. */
    public function testAContactRemovedInKanvasStaysRemoved(): void
    {
        $people = $this->action()->fromRow($this->row());
        $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::EMAIL))->update(['is_deleted' => 1]);

        $this->action()->fromRow($this->row(['name' => 'Jane Roe']));

        $this->assertSame(0, $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::EMAIL))->count());
    }

    public function testARewrittenAddressDropsTheOldBounce(): void
    {
        $people = $this->action()->fromRow($this->row());
        $people->getEmails()->first()->markBounce(true);

        $this->action()->fromRow($this->row(['email' => 'jane.new@example.com']));

        $email = $people->getEmails()->first();
        $this->assertSame('jane.new@example.com', $email->value);
        $this->assertSame(ContactValidationStatusEnum::VALID, $email->fresh()->validation_status);
        $this->assertNull($email->fresh()->bounced_at);
    }

    public function testValuesClearedAtTheSourceAreDeleted(): void
    {
        $people = $this->action()->fromRow($this->row(['display_name' => 'JD']));

        $this->action()->fromRow($this->row([
            'display_name' => null,
            'favorite_studio' => null,
            'settings' => '{}',
            'birthday' => 0,
        ]));

        $this->assertNull($people->get(CustomFieldEnum::BRUSHCRAZY_DISPLAY_NAME->value));
        $this->assertNull($people->get(CustomFieldEnum::BRUSHCRAZY_FAVORITE_STUDIO_ID->value));
        $this->assertNull($people->get(CustomFieldEnum::BRUSHCRAZY_PROMOTION_EMAILS->value));
        $this->assertNull($people->get(CustomFieldEnum::BRUSHCRAZY_BIRTHDAY_MONTH->value));
    }

    /** Every credit row reverted: the user drops out of the source query, the old balance must not stay. */
    public function testAStoredCreditIsZeroedWhenTheSourceHasNoneLeft(): void
    {
        $people = $this->action(new FakeStudioCredits([$this->bcUserId => 1250]))->fromRow($this->row());

        $this->action(new FakeStudioCredits())->fromRow($this->row());

        $this->assertSame(0, (int) $people->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_CREDIT_CENTS->value));
    }

    public function testAUserWhoNeverHadCreditGetsNoCreditField(): void
    {
        $people = $this->action()->fromRow($this->row());

        $this->assertNull($people->getCustomField(CustomFieldEnum::BRUSHCRAZY_STUDIO_CREDIT_CENTS->value));
    }

    /** An unchanged re-sync writes no custom field: set() would cost three queries and a Redis write each. */
    public function testAnUnchangedReSyncOfAPreloadedChunkWritesNoCustomField(): void
    {
        $this->action()->fromRow($this->row());
        $action = $this->action();
        $action->preloadRows([$this->row()]);

        DB::connection('ecosystem')->flushQueryLog();
        DB::connection('ecosystem')->enableQueryLog();
        $action->fromRow($this->row());
        $queries = DB::connection('ecosystem')->getQueryLog();
        DB::connection('ecosystem')->disableQueryLog();

        foreach ($queries as $query) {
            $this->assertStringNotContainsString('custom_fields_modules', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update)\b.*apps_custom_fields/is', $query['query']);
        }
    }

    public function testStoresAnInternationalPhoneWithItsCountryCode(): void
    {
        $people = $this->action()->fromRow($this->row(['phone_number' => '+44 20 7946 0958']));

        $this->assertSame(
            '442079460958',
            $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::PHONE))->value('value')
        );
    }

    /** A re-sync of an unchanged number reformatted at the source still matches the stored contact. */
    public function testAReformattedPhoneMatchesTheStoredContact(): void
    {
        $people = $this->action()->fromRow($this->row(['phone_number' => '(614) 555-0100']));
        $this->action()->fromRow($this->row(['phone_number' => '+1 614 555 0100']));

        $this->assertSame(1, $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::PHONE))->count());
    }

    public function testAChangedSourceEmailReplacesTheMirroredContactAndKeepsItsOptOut(): void
    {
        $people = $this->action()->fromRow($this->row());
        $people->contacts()->update(['is_opt_out' => 1]);

        $this->action()->fromRow($this->row(['email' => 'jane.new@example.com']));

        $emails = $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::EMAIL))->get();

        $this->assertCount(1, $emails);
        $this->assertSame('jane.new@example.com', $emails->first()->value);
        $this->assertSame(1, (int) $emails->first()->is_opt_out);
    }

    /** Only the contact the mirror manages is replaced; one added in Kanvas is not the mirror's. */
    public function testAChangedSourceEmailLeavesAContactAddedInKanvas(): void
    {
        $people = $this->action()->fromRow($this->row());
        $people->addEmail('jane.work@example.com');

        $this->action()->fromRow($this->row(['email' => 'jane.new@example.com']));

        $this->assertEqualsCanonicalizing(
            ['jane.work@example.com', 'jane.new@example.com'],
            $people->contacts()->where('contacts_types_id', $this->contactTypeId(ContactTypeEnum::EMAIL))->pluck('value')->all()
        );
    }

    /** addDefaultAddress() keys on the full text, so a corrected street used to become a second row. */
    public function testACorrectedAddressIsEditedInPlace(): void
    {
        $people = $this->action()->fromRow($this->row());
        $this->action()->fromRow($this->row(['route' => 'Main Street']));

        $this->assertSame(1, $people->address()->count());
        $this->assertSame('123 Main Street', $people->getDefaultAddress()?->address);
    }

    /** Deleted in Kanvas by a user, not by the mirror: a re-sync must not bring it back. */
    public function testAPeopleDeletedInKanvasIsNotResurrected(): void
    {
        $people = $this->action()->fromRow($this->row());
        People::withTrashed()->whereKey($people->getId())->update(['is_deleted' => 1]);

        $this->action()->fromRow($this->row(['name' => 'Jane Roe']));

        $this->assertTrue((bool) People::withTrashed()->find($people->getId())->is_deleted);
    }

    public function testASourceDeleteIsUndoneByASourceRestore(): void
    {
        $people = $this->action()->fromRow($this->row());
        $this->action()->fromRow($this->row(['deleted_at' => '2025-01-01 00:00:00']));
        $this->assertTrue((bool) People::withTrashed()->find($people->getId())->is_deleted);

        $this->action()->fromRow($this->row());

        $this->assertFalse((bool) People::withTrashed()->find($people->getId())->is_deleted);
    }

    public function testAPreloadedChunkReadsStudioCreditOnceAndStoresIt(): void
    {
        $credits = new FakeStudioCredits([$this->bcUserId => 1250]);
        $action = $this->action($credits);

        $action->preload([$this->bcUserId, $this->bcUserId + 1]);
        $people = $action->fromRow($this->row());
        $action->fromRow($this->row(['id' => $this->bcUserId + 1, 'email' => 'other@example.com']));

        $this->assertSame(1, $credits->calls);
        $this->assertSame(1250, (int) $people->get(CustomFieldEnum::BRUSHCRAZY_STUDIO_CREDIT_CENTS->value));
    }

    /**
     * The real credit source is built only when none is injected, which every other test does; this
     * one takes that branch so a broken constructor call cannot hide behind the fake.
     */
    public function testWithoutAnInjectedSourceTheCreditsAreReadFromTheBrushCrazyDatabase(): void
    {
        if (app(Apps::class)->get(ConfigurationEnum::BRUSHCRAZY_DB_HOST->value)) {
            $this->markTestSkipped('A BrushCrazy database is configured for this app.');
        }

        $this->expectException(ValidationException::class);

        new SyncCustomerFromBrushCrazyAction(
            app(Apps::class),
            auth()->user(),
            $this->context(),
        )->preload([$this->bcUserId]);
    }

    private function action(?FakeStudioCredits $credits = null): SyncCustomerFromBrushCrazyAction
    {
        return new SyncCustomerFromBrushCrazyAction(
            app: app(Apps::class),
            user: auth()->user(),
            context: $this->context(),
            credits: $credits ?? new FakeStudioCredits(),
        );
    }

    private function context(): StudioContext
    {
        $company = auth()->user()->getCurrentCompany();

        return new StudioContext(
            bcStudioId: 2,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: new ThemeArea(),
            timezone: 'America/New_York',
        );
    }

    private function nativePeople(string $email): People
    {
        $company = auth()->user()->getCurrentCompany();
        $people = People::factory()->withAppId(app(Apps::class)->getId())->withCompanyId($company->getId())->create();
        $people->addEmail($email);

        return $people;
    }

    private function contactTypeId(ContactTypeEnum $type): int
    {
        return (int) ContactType::getByName($type->getName())->getId();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function row(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => $this->bcUserId,
            'name' => 'Jane Doe',
            'display_name' => null,
            'email' => 'Jane@Example.com',
            'phone_number' => '(614) 555-0100',
            'birthday' => 7,
            'street_number' => '123',
            'route' => 'Main St',
            'suite' => null,
            'locality' => 'Columbus',
            'administrative_area_level_1' => 'OH',
            'postal_code' => '43004',
            'points' => 10,
            'rewards' => '{}',
            'settings' => '{"promotionEmails":true}',
            'favorite_studio' => 2,
            'last_studio' => 2,
            'created_at' => '2020-05-01 10:00:00',
            'deleted_at' => null,
        ]);
    }
}
