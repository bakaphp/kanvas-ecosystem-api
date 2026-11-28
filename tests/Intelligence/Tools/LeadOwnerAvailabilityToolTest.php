<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\LeadOwnerAvailabilityTool;
use Kanvas\Users\Enums\UserDayOffTypeEnum;
use Kanvas\Users\Models\UserDayOff;
use Kanvas\Users\Models\Users;
use Override;
use Tests\TestCase;

class LeadOwnerAvailabilityToolTest extends TestCase
{
    private const string TIMEZONE = 'America/New_York';

    private const array SETTINGS = [
        ConfigurationEnum::WORKING_HOURS->value,
        ConfigurationEnum::WORKING_DAYS->value,
        ConfigurationEnum::WORKING_HOLIDAY_DAYS->value,
    ];

    private Apps $kanvasApp;

    private Companies $company;

    private Users $owner;

    private ?string $originalTimezone;

    /** @var array<string, mixed> */
    private array $originalSettings = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->owner = auth()->user();
        $this->company = $this->owner->getCurrentCompany();

        $this->originalTimezone = $this->company->timezone;
        foreach (self::SETTINGS as $setting) {
            $this->originalSettings[$setting] = $this->company->get($setting);
        }

        $this->company->timezone = self::TIMEZONE;
        $this->company->saveOrFail();
        $this->company->set(ConfigurationEnum::WORKING_DAYS->value, []);
        $this->company->set(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value, []);
        $this->company->set(ConfigurationEnum::WORKING_HOURS->value, array_fill_keys(
            ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            '08:00 - 21:00'
        ));

        UserDayOff::query()->where('users_id', $this->owner->getId())->delete();
    }

    #[Override]
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        UserDayOff::query()->where('users_id', $this->owner->getId())->delete();
        $this->company->timezone = $this->originalTimezone;
        $this->company->saveOrFail();
        foreach ($this->originalSettings as $setting => $value) {
            $value === null ? $this->company->del($setting) : $this->company->set($setting, $value);
        }

        parent::tearDown();
    }

    public function testOwnerIsWorkingDuringBusinessHours(): void
    {
        $this->freezeAt('2026-01-07 12:00');

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_WORKING, $result['status']);
        $this->assertSame($this->owner->firstname, $result['owner_name']);
        $this->assertArrayNotHasKey('returns_on', $result);
    }

    public function testOwnerIsAfterHoursWhenTheDealershipIsClosed(): void
    {
        $this->freezeAt('2026-01-07 23:00');

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_AFTER_HOURS, $result['status']);
        $this->assertSame('2026-01-08', $result['returns_on']);
    }

    public function testAfterHoursReturnDateSkipsTheNextDayOff(): void
    {
        $this->freezeAt('2026-01-07 23:00');
        $this->addDayOff(UserDayOffTypeEnum::DAY_OFF, '2026-01-08', '2026-01-08');

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_AFTER_HOURS, $result['status']);
        $this->assertSame('2026-01-09', $result['returns_on']);
    }

    public function testOwnerOnVacationReturnsOnTheFirstDayTheyWork(): void
    {
        $this->freezeAt('2026-01-07 12:00');
        $this->addDayOff(UserDayOffTypeEnum::VACATION, '2026-01-05', '2026-01-09');
        $this->addDayOff(UserDayOffTypeEnum::DAY_OFF, days: [6, 0]);

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_VACATION, $result['status']);
        $this->assertSame('2026-01-12', $result['returns_on']);
        $this->assertSame('Monday, January 12', $result['returns_on_human']);
    }

    public function testVacationWinsOverADayOffOnTheSameDate(): void
    {
        $this->freezeAt('2026-01-07 12:00');
        $this->addDayOff(UserDayOffTypeEnum::DAY_OFF, days: [3]);
        $this->addDayOff(UserDayOffTypeEnum::VACATION, '2026-01-05', '2026-01-09');

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_VACATION, $result['status']);
    }

    public function testFixedWeeklyDayOff(): void
    {
        $this->freezeAt('2026-01-07 12:00');
        $this->addDayOff(UserDayOffTypeEnum::DAY_OFF, days: [3]);

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_DAY_OFF, $result['status']);
        $this->assertSame('2026-01-08', $result['returns_on']);
    }

    public function testWeekdaysOnlyApplyInsideTheirDateRange(): void
    {
        $this->freezeAt('2026-01-07 12:00');
        $this->addDayOff(
            UserDayOffTypeEnum::DAY_OFF,
            '2026-02-01',
            '2026-02-28',
            [3]
        );

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_WORKING, $result['status']);
    }

    public function testDayOffOfAnotherCompanyIsIgnored(): void
    {
        $this->freezeAt('2026-01-07 12:00');
        $this->addDayOff(
            UserDayOffTypeEnum::VACATION,
            '2026-01-05',
            '2026-01-09',
            company: Companies::factory()->create()
        );

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_WORKING, $result['status']);
    }

    public function testOpenEndedAbsenceHasNoReturnDate(): void
    {
        $this->freezeAt('2026-01-07 12:00');
        $this->addDayOff(UserDayOffTypeEnum::VACATION, '2026-01-05');

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_VACATION, $result['status']);
        $this->assertArrayNotHasKey('returns_on', $result);
    }

    public function testReturnDateSkipsTheDaysTheCompanyIsClosed(): void
    {
        $this->freezeAt('2026-01-10 12:00');
        $hours = $this->company->get(ConfigurationEnum::WORKING_HOURS->value);
        unset($hours['Sunday']);
        $this->company->set(ConfigurationEnum::WORKING_HOURS->value, $hours);
        $this->addDayOff(UserDayOffTypeEnum::DAY_OFF, days: [6]);

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_DAY_OFF, $result['status']);
        $this->assertSame('2026-01-12', $result['returns_on']);
    }

    public function testReturnDateSkipsAHolidayTheCompanyObserves(): void
    {
        $this->freezeAt('2026-12-22 12:00');
        $this->addDayOff(UserDayOffTypeEnum::VACATION, '2026-12-21', '2026-12-23');

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_VACATION, $result['status']);
        $this->assertSame('2026-12-26', $result['returns_on']);
    }

    public function testBeforeOpeningTheOwnerIsBackTheSameDay(): void
    {
        $this->freezeAt('2026-01-07 06:00');

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_AFTER_HOURS, $result['status']);
        $this->assertSame('2026-01-07', $result['returns_on']);
    }

    public function testAfterHoursWithSimpleHoursReturnsTheNextDay(): void
    {
        $this->freezeAt('2026-01-07 23:00');
        $this->company->set(ConfigurationEnum::WORKING_HOURS->value, [
            'opens_at_local' => '08:00',
            'closes_at_local' => '21:00',
        ]);

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_AFTER_HOURS, $result['status']);
        $this->assertSame('2026-01-08', $result['returns_on']);
    }

    public function testEntryWithNoDatesAndNoWeekdaysIsIgnored(): void
    {
        $this->freezeAt('2026-01-07 12:00');
        $this->addDayOff(UserDayOffTypeEnum::DAY_OFF);

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_WORKING, $result['status']);
    }

    public function testLeadWithoutOwner(): void
    {
        $this->freezeAt('2026-01-07 12:00');
        $lead = $this->makeLead();
        $lead->leads_owner_id = 0;
        $lead->saveOrFail();

        $result = $this->invoke($lead);

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_NO_OWNER, $result['status']);
        $this->assertArrayNotHasKey('owner_name', $result);
    }

    public function testUnknownWhenTheCompanyHasNoBusinessHours(): void
    {
        $this->freezeAt('2026-01-07 12:00');
        $this->company->set(ConfigurationEnum::WORKING_HOURS->value, []);

        $result = $this->invoke($this->makeLead());

        $this->assertSame(LeadOwnerAvailabilityTool::STATUS_UNKNOWN, $result['status']);
    }

    public function testLeadOfAnotherCompanyIsNotResolved(): void
    {
        $foreignLead = Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId(Companies::factory()->create()->getId())
            ->create();

        $result = $this->invoke($foreignLead);

        $this->assertSame('error', $result['status']);
    }

    private function freezeAt(string $localTime): void
    {
        Carbon::setTestNow(Carbon::parse($localTime, self::TIMEZONE));
    }

    private function makeLead(): Lead
    {
        $lead = Lead::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();
        $lead->leads_owner_id = $this->owner->getId();
        $lead->saveOrFail();

        return $lead;
    }

    /**
     * @param array<int, int>|null $days
     */
    private function addDayOff(
        UserDayOffTypeEnum $type,
        ?string $startDate = null,
        ?string $endDate = null,
        ?array $days = null,
        ?Companies $company = null,
    ): UserDayOff {
        return UserDayOff::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => ($company ?? $this->company)->getId(),
            'users_id' => $this->owner->getId(),
            'type' => $type,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days' => $days,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoke(Lead $lead): array
    {
        return new LeadOwnerAvailabilityTool()
            ->withContext($this->kanvasApp, $this->company, $this->owner)
            ->__invoke(lead_id: $lead->getId());
    }
}
