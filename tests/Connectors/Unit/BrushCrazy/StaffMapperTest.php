<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\BrushCrazy;

use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\StaffMapper;
use Tests\TestCase;

final class StaffMapperTest extends TestCase
{
    public function testCurrentEmployeeIsActiveWithTheirCurrentPositionAndBadge(): void
    {
        $mapped = StaffMapper::map($this->user(), [
            $this->employment(['id' => 1, 'position' => 'Artist', 'transitioned_at' => '2022-01-01 00:00:00']),
            $this->employment(['id' => 2, 'position' => 'Manager', 'employee_id' => 4321, 'hired_at' => '2022-01-01 00:00:00']),
        ], false);

        $fields = $mapped['custom_fields'];

        $this->assertFalse($mapped['is_deleted']);
        $this->assertTrue($fields[CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value]);
        $this->assertSame('Manager', $fields[CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_POSITION->value]);
        $this->assertSame(4321, $fields[CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_BADGE->value]);
        $this->assertSame(42, $fields[CustomFieldEnum::BRUSHCRAZY_USER_ID->value]);
        $this->assertNull($fields[CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_TERMINATED_AT->value]);
    }

    /** A transition rewrites `hired_at`, so the stint starts at its earliest row, not the current one. */
    public function testHiredAtIsTheStartOfTheStintNotOfTheLatestPosition(): void
    {
        $fields = StaffMapper::map($this->user(), [
            $this->employment(['id' => 1, 'hired_at' => '2020-03-01 15:00:00', 'transitioned_at' => '2022-01-01 00:00:00']),
            $this->employment(['id' => 2, 'hired_at' => '2022-01-01 00:00:00']),
        ], false)['custom_fields'];

        $this->assertSame('2020-03-01T15:00:00+00:00', $fields[CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_HIRED_AT->value]);
    }

    /** Kept live so the classes they taught still list them; only marked inactive. */
    public function testTerminatedEmployeeStaysLiveButInactiveWithTheTerminationDate(): void
    {
        $mapped = StaffMapper::map($this->user(), [
            $this->employment(['id' => 1, 'position' => 'Artist', 'terminated_at' => '2023-06-30 12:00:00']),
        ], false);

        $this->assertFalse($mapped['is_deleted']);
        $this->assertFalse($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value]);
        $this->assertSame('Artist', $mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_POSITION->value]);
        $this->assertSame('2023-06-30T12:00:00+00:00', $mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_TERMINATED_AT->value]);
    }

    /** A rehire starts a new stint: the old termination is cleared and the hire date moves. */
    public function testRehireClearsTheTerminationAndRestartsTheStint(): void
    {
        $mapped = StaffMapper::map($this->user(), [
            $this->employment(['id' => 1, 'hired_at' => '2019-01-01 00:00:00', 'terminated_at' => '2020-01-01 00:00:00']),
            $this->employment(['id' => 2, 'hired_at' => '2024-02-01 00:00:00']),
        ], false);

        $this->assertFalse($mapped['is_deleted']);
        $this->assertTrue($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value]);
        $this->assertNull($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_TERMINATED_AT->value]);
        $this->assertSame('2024-02-01T00:00:00+00:00', $mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_HIRED_AT->value]);
    }

    /** Moved to another studio: only transitioned here, never terminated, but no longer working here. */
    public function testEmployeeWhoMovedAwayIsInactiveWithoutATerminationDate(): void
    {
        $mapped = StaffMapper::map($this->user(), [
            $this->employment(['id' => 1, 'transitioned_at' => '2024-01-01 00:00:00']),
        ], false);

        $this->assertFalse($mapped['is_deleted']);
        $this->assertFalse($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value]);
        $this->assertNull($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_TERMINATED_AT->value]);
    }

    public function testOwnerWithoutEmploymentRowsIsActive(): void
    {
        $mapped = StaffMapper::map($this->user(), [], true);

        $this->assertFalse($mapped['is_deleted']);
        $this->assertTrue($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value]);
        $this->assertTrue($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_STUDIO_OWNER->value]);
        $this->assertNull($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_EMPLOYMENT_HISTORY->value]);
        $this->assertNull($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_POSITION->value]);
    }

    /** Assigned to teach here without working here: live so the class shows them, but not active staff. */
    public function testAssigneeOnlyIsLiveButInactive(): void
    {
        $mapped = StaffMapper::map($this->user(), [], false);

        $this->assertFalse($mapped['is_deleted']);
        $this->assertFalse($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value]);
    }

    public function testDeletedUserIsSoftDeletedEvenWhileEmployed(): void
    {
        $mapped = StaffMapper::map(
            $this->user(['deleted_at' => '2025-01-01 00:00:00']),
            [$this->employment()],
            true
        );

        $this->assertTrue($mapped['is_deleted']);
        $this->assertFalse($mapped['custom_fields'][CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value]);
    }

    /** The latest of several stray current rows wins, whatever order the rows arrive in. */
    public function testLatestCurrentRowWinsRegardlessOfInputOrder(): void
    {
        $fields = StaffMapper::map($this->user(), [
            $this->employment(['id' => 9, 'position' => 'Manager']),
            $this->employment(['id' => 3, 'position' => 'Artist']),
        ], false)['custom_fields'];

        $this->assertSame('Manager', $fields[CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_POSITION->value]);
    }

    /** The history is a Facilitator custom field any company user can read, so it carries no wage. */
    public function testHistoryIsOrderedAndCarriesNoWage(): void
    {
        $history = StaffMapper::map($this->user(), [
            $this->employment(['id' => 2, 'wage' => '{"amount":"1650","currency":"USD"}']),
            $this->employment(['id' => 1, 'wage' => '{"amount":1500,"currency":"USD"}', 'transitioned_at' => '2022-01-01 00:00:00']),
        ], false)['custom_fields'][CustomFieldEnum::BRUSHCRAZY_EMPLOYMENT_HISTORY->value];

        $this->assertSame([1, 2], array_column($history, 'id'));
        $this->assertSame([], array_column($history, 'wage_cents'));
        $this->assertStringNotContainsString('1650', (string) json_encode($history));
        $this->assertSame('2022-01-01T00:00:00+00:00', $history[0]['transitioned_at']);
        $this->assertNull($history[1]['transitioned_at']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function user(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 42,
            'name' => 'Jane Doe',
            'deleted_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function employment(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => 1,
            'studio_id' => 2,
            'user_id' => 42,
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
