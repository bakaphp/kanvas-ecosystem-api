<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCustomerFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncStaffFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\PeopleType;
use Tests\TestCase;

final class SyncStaffFromBrushCrazyActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'event', 'ecosystem'];

    private int $bcUserId;

    protected function setUp(): void
    {
        parent::setUp();

        // Custom fields also land in Redis, which never rolls back — a fresh source id per test
        // keeps one run from resolving another run's rows.
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

    public function testCreatesAFacilitatorLinkedToThePeopleWithExternalIdAndHistory(): void
    {
        $facilitator = $this->action()->fromRow($this->userRow(), [$this->employment()], false);

        $this->assertNotEmpty($facilitator->uuid);
        $this->assertStringEndsWith('bc-' . $this->bcUserId, $facilitator->slug);
        $this->assertFalse((bool) $facilitator->is_deleted);
        $this->assertSame((int) $facilitator->people->getId(), (int) $facilitator->people_id);
        $this->assertSame('Jane Doe', $facilitator->people->name);
        $this->assertSame($this->bcUserId, (int) $facilitator->get(CustomFieldEnum::BRUSHCRAZY_USER_ID->value));
        $this->assertSame('Artist', $facilitator->get(CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_POSITION->value));
        $this->assertCount(1, $facilitator->get(CustomFieldEnum::BRUSHCRAZY_EMPLOYMENT_HISTORY->value));
    }

    public function testReSyncUpdatesTheSameFacilitator(): void
    {
        $first = $this->action()->fromRow($this->userRow(), [$this->employment()], false);
        $second = $this->action()->fromRow($this->userRow(), [$this->employment(['position' => 'Manager'])], false);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame('Manager', $second->get(CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_POSITION->value));
    }

    /** A staff member who also bought in the studio is one People, the one the customer sync made. */
    public function testReusesThePeopleTheCustomerSyncCreated(): void
    {
        $people = $this->customerAction()->fromRow($this->userRow());

        $facilitator = $this->action()->fromRow($this->userRow(), [$this->employment()], false);

        $this->assertSame((int) $people->getId(), (int) $facilitator->people_id);
    }

    /** A former instructor stays live so the classes they taught keep listing them. */
    public function testTerminationMarksInactiveAndARehireReactivatesTheSameFacilitator(): void
    {
        $terminated = $this->employment(['terminated_at' => '2023-06-30 12:00:00']);

        $fired = $this->action()->fromRow($this->userRow(), [$terminated], false);
        $this->assertFalse((bool) Facilitator::withTrashed()->find($fired->getId())->is_deleted);
        $this->assertFalse((bool) $fired->getCustomField(CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value)?->value);
        $this->assertNotNull($fired->get(CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_TERMINATED_AT->value));

        $rehired = $this->action()->fromRow(
            $this->userRow(),
            [$terminated, $this->employment(['id' => 2, 'hired_at' => '2024-02-01 00:00:00'])],
            false
        );

        $this->assertSame($fired->getId(), $rehired->getId());
        $this->assertTrue((bool) $rehired->get(CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value));
        $this->assertNull($rehired->getCustomField(CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_TERMINATED_AT->value));
    }

    /** A user whose only employment row was soft-deleted drops out of staffOfStudio() entirely. */
    public function testDeactivateMarksInactiveWithoutDeletingAFacilitatorNoLongerStaff(): void
    {
        $facilitator = $this->action()->fromRow($this->userRow(), [$this->employment()], false);

        $deactivated = $this->action()->deactivate($this->bcUserId);

        $this->assertSame($facilitator->getId(), $deactivated->getId());
        $this->assertFalse((bool) Facilitator::withTrashed()->find($facilitator->getId())->is_deleted);
        $this->assertFalse((bool) $deactivated->getCustomField(CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value)?->value);
    }

    /** Deleted in Kanvas by a user, not by the mirror: a re-sync must not bring it back. */
    public function testAFacilitatorDeletedInKanvasIsNotResurrected(): void
    {
        $facilitator = $this->action()->fromRow($this->userRow(), [$this->employment()], false);
        Facilitator::withTrashed()->whereKey($facilitator->getId())->update(['is_deleted' => 1]);

        $this->action()->fromRow($this->userRow(), [$this->employment()], false);

        $this->assertTrue((bool) Facilitator::withTrashed()->find($facilitator->getId())->is_deleted);
    }

    public function testASourceUserDeleteIsUndoneByASourceRestore(): void
    {
        $deletedRow = (object) (['deleted_at' => '2025-01-01 00:00:00'] + (array) $this->userRow());

        $deleted = $this->action()->fromRow($deletedRow, [$this->employment()], false);
        $this->assertTrue((bool) Facilitator::withTrashed()->find($deleted->getId())->is_deleted);

        $restored = $this->action()->fromRow($this->userRow(), [$this->employment()], false);

        $this->assertSame($deleted->getId(), $restored->getId());
        $this->assertFalse((bool) Facilitator::withTrashed()->find($restored->getId())->is_deleted);
    }

    /** The history is readable by any company user through custom_fields. */
    public function testTheStoredHistoryCarriesNoWage(): void
    {
        $facilitator = $this->action()->fromRow($this->userRow(), [$this->employment()], false);

        $history = $facilitator->get(CustomFieldEnum::BRUSHCRAZY_EMPLOYMENT_HISTORY->value);

        $this->assertArrayNotHasKey('wage_cents', $history[0]);
    }

    public function testDeactivateIgnoresAUserThatNeverHadAFacilitatorHere(): void
    {
        $this->assertNull($this->action()->deactivate($this->bcUserId));
    }

    public function testPreloadedChunkResolvesTheExistingFacilitator(): void
    {
        $first = $this->action()->fromRow($this->userRow(), [$this->employment()], false);

        $action = $this->action();
        $action->preload([$this->bcUserId]);

        $this->assertSame($first->getId(), $action->fromRow($this->userRow(), [$this->employment()], false)->getId());
    }

    private function action(): SyncStaffFromBrushCrazyAction
    {
        return new SyncStaffFromBrushCrazyAction(
            app: app(Apps::class),
            user: auth()->user(),
            context: $this->context(),
            credits: new FakeStudioCredits(),
        );
    }

    private function customerAction(): SyncCustomerFromBrushCrazyAction
    {
        return new SyncCustomerFromBrushCrazyAction(
            app: app(Apps::class),
            user: auth()->user(),
            context: $this->context(),
            credits: new FakeStudioCredits(),
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

    private function userRow(): object
    {
        return (object) [
            'id' => $this->bcUserId,
            'name' => 'Jane Doe',
            'display_name' => null,
            'email' => 'jane.staff@example.com',
            'phone_number' => null,
            'birthday' => null,
            'street_number' => null,
            'route' => null,
            'suite' => null,
            'locality' => null,
            'administrative_area_level_1' => null,
            'postal_code' => null,
            'points' => 0,
            'rewards' => null,
            'settings' => null,
            'favorite_studio' => null,
            'last_studio' => null,
            'created_at' => '2020-05-01 10:00:00',
            'deleted_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function employment(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'studio_id' => 2,
            'user_id' => $this->bcUserId,
            'employee_id' => 1234,
            'position' => 'Artist',
            'wage' => '{"amount":1500,"currency":"USD"}',
            'hired_at' => '2021-05-01 00:00:00',
            'invited_at' => '2021-04-30 00:00:00',
            'verified_at' => '2021-05-01 00:00:00',
            'terminated_at' => null,
            'transitioned_at' => null,
            'deleted_at' => null,
        ]);
    }
}
