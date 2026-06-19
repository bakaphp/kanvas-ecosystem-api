<?php

declare(strict_types=1);

namespace Kanvas\Companies\Services;

use Carbon\Carbon;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Yasumi\Yasumi;

class CompanyHolidayService
{
    public function __construct(
        private readonly Companies $company,
    ) {
    }

    /**
     * Resolve the holiday status for a given date: whether it is a holiday, whether
     * the company stays open on it (working_holiday_days) and whether the company
     * recognizes it for the AI to acknowledge (recognized_holiday_days).
     */
    public function check(?Carbon $date = null): array
    {
        $date ??= Carbon::today();

        $workingHolidays = $this->company->get(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value) ?? [];
        $recognizedHolidays = $this->company->get(ConfigurationEnum::RECOGNIZED_HOLIDAY_DAYS->value) ?? [];

        $holidayName = $this->resolveHolidayName($date);
        $isWorkingDay = $this->matches($holidayName, $workingHolidays);
        $isRecognizedHoliday = $this->matches($holidayName, $recognizedHolidays);

        //@todo this is a temp solution till frontend changes to use is_recognized
        $isHoliday = empty($recognizedHolidays) ? $holidayName !== null : $isRecognizedHoliday;

        return [
            'is_holiday' => $isHoliday,
            'is_a_working_day' => $isWorkingDay,
            'is_recognized_holiday' => $isRecognizedHoliday,
            'holiday_info' => $isHoliday ? [
                'holiday' => $holidayName,
                'company_observes' => $isWorkingDay,
                'company_recognizes' => $isRecognizedHoliday,
            ] : null,
            'date_checked' => $date->toDateString(),
        ];
    }

    /**
     * Resolve the name of the holiday on the given date, covering both US federal
     * holidays (via Yasumi) and the common non-federal days companies recognize
     * (Mother's/Father's Day, New Year's Eve, etc.) that Yasumi omits.
     */
    private function resolveHolidayName(Carbon $date): ?string
    {
        $usHolidays = Yasumi::create('USA', $date->year);
        foreach ($usHolidays as $holiday) {
            if ($holiday->format('Y-m-d') === $date->format('Y-m-d')) {
                return $holiday->getName();
            }
        }

        foreach ($this->nonFederalHolidays($date->year) as $name => $holidayDate) {
            if ($holidayDate === $date->format('Y-m-d')) {
                return $name;
            }
        }

        if ($this->company->get('holiday_epiphany') && $date->format('m-d') === '01-06') {
            return 'Epiphany';
        }

        return null;
    }

    /**
     * Date (Y-m-d) for each non-federal holiday companies commonly recognize.
     */
    private function nonFederalHolidays(int $year): array
    {
        return [
            "Valentine's Day" => Carbon::create($year, 2, 14)->toDateString(),
            "Mother's Day" => Carbon::parse("second sunday of may $year")->toDateString(),
            "Father's Day" => Carbon::parse("third sunday of june $year")->toDateString(),
            'Halloween' => Carbon::create($year, 10, 31)->toDateString(),
            'Christmas Eve' => Carbon::create($year, 12, 24)->toDateString(),
            "New Year's Eve" => Carbon::create($year, 12, 31)->toDateString(),
        ];
    }

    /**
     * Fuzzy-match a resolved holiday name against a free-text config list.
     * Tolerates apostrophes, the trailing word "Day", and singular/plural
     * variants (e.g. "New Year" matches "New Year's Day").
     */
    private function matches(?string $holidayName, array $list): bool
    {
        if ($holidayName === null) {
            return false;
        }

        $target = $this->normalize($holidayName);
        if ($target === '') {
            return false;
        }

        foreach ($list as $candidate) {
            $normalized = $this->normalize((string) $candidate);
            if ($normalized === '') {
                continue;
            }

            if (str_contains($normalized, $target) || str_contains($target, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $name): string
    {
        $name = strtolower($name);
        $name = str_replace("'", '', $name);
        $name = (string) preg_replace('/[^a-z0-9]+/', ' ', $name);
        $name = (string) preg_replace('/\bday\b/', '', $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
