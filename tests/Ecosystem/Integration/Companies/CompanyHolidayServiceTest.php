<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Carbon\Carbon;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Services\CompanyHolidayService;
use Tests\TestCase;

class CompanyHolidayServiceTest extends TestCase
{
    public function testRecognizedHolidayWithFuzzyNameMatch(): void
    {
        $company = auth()->user()->getCurrentCompany();
        // Typo'd / variant free-text values like the UI produces.
        $company->set(ConfigurationEnum::RECOGNIZED_HOLIDAY_DAYS->value, ['Thanksgiving', 'Chrismas Eve']);
        $company->set(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value, []);

        // Thanksgiving 2026 = 4th Thursday of November.
        Carbon::setTestNow(Carbon::create(2026, 11, 26));

        $result = new CompanyHolidayService($company)->check();

        $this->assertTrue($result['is_holiday']);
        $this->assertTrue($result['is_recognized_holiday']);
        $this->assertFalse($result['is_a_working_day']);
        $this->assertSame('Thanksgiving Day', $result['holiday_info']['holiday']);

        Carbon::setTestNow();
    }

    public function testNonFederalRecognizedHolidayFathersDay(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::RECOGNIZED_HOLIDAY_DAYS->value, ['Fathers Day']);
        $company->set(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value, ['Fathers Day']);

        // Father's Day 2026 = 3rd Sunday of June.
        Carbon::setTestNow(Carbon::create(2026, 6, 21));

        $result = new CompanyHolidayService($company)->check();

        $this->assertTrue($result['is_holiday']);
        $this->assertTrue($result['is_recognized_holiday']);
        $this->assertTrue($result['is_a_working_day']);
        $this->assertSame("Father's Day", $result['holiday_info']['holiday']);

        Carbon::setTestNow();
    }

    public function testNonHolidayReturnsNoHoliday(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $company->set(ConfigurationEnum::RECOGNIZED_HOLIDAY_DAYS->value, ['Christmas']);

        Carbon::setTestNow(Carbon::create(2026, 3, 17));

        $result = new CompanyHolidayService($company)->check();

        $this->assertFalse($result['is_holiday']);
        $this->assertFalse($result['is_recognized_holiday']);
        $this->assertFalse($result['is_a_working_day']);
        $this->assertNull($result['holiday_info']);

        Carbon::setTestNow();
    }

    public function testFederalHolidayNotInRecognizedListReportsNotHoliday(): void
    {
        $company = auth()->user()->getCurrentCompany();
        // When a recognized list is configured, only recognized holidays count as "is_holiday".
        $company->set(ConfigurationEnum::RECOGNIZED_HOLIDAY_DAYS->value, ['Christmas']);
        $company->set(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value, []);

        // Independence Day 2026 — a federal holiday the company does not recognize.
        Carbon::setTestNow(Carbon::create(2026, 7, 4));

        $result = new CompanyHolidayService($company)->check();

        $this->assertFalse($result['is_holiday']);
        $this->assertFalse($result['is_recognized_holiday']);

        Carbon::setTestNow();
    }

    public function testFederalHolidayCountsWhenNoRecognizedListConfigured(): void
    {
        $company = auth()->user()->getCurrentCompany();
        // No recognized list configured — fall back to "any resolved holiday is a holiday".
        $company->set(ConfigurationEnum::RECOGNIZED_HOLIDAY_DAYS->value, []);
        $company->set(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value, []);

        Carbon::setTestNow(Carbon::create(2026, 7, 4));

        $result = new CompanyHolidayService($company)->check();

        $this->assertTrue($result['is_holiday']);
        $this->assertFalse($result['is_recognized_holiday']);
        $this->assertSame('Independence Day', $result['holiday_info']['holiday']);

        Carbon::setTestNow();
    }
}
