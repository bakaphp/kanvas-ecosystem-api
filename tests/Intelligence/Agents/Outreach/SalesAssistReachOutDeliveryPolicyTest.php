<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Outreach;

use Carbon\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Activities\SalesAssistAgentReachOutActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Activities\AgentReachOutActivity;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SalesAssistReachOutDeliveryPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function deliveryTimes(): array
    {
        return [
            'open local noon' => ['2026-10-02 16:00:00', true, true],
            'before opening' => ['2026-10-02 12:00:00', true, false],
            'after closing' => ['2026-10-02 23:00:00', true, false],
            'nonworking Sunday' => ['2026-10-04 16:00:00', true, false],
            'closed Christmas' => ['2026-12-25 17:00:00', true, false],
            'full on while open' => ['2026-10-02 16:00:00', false, false],
            'full on after hours' => ['2026-10-02 23:00:00', false, false],
        ];
    }

    #[DataProvider('deliveryTimes')]
    public function testSalesAssistSupportDelayOnlyAppliesDuringWorkHours(string $utc, bool $support, bool $deferred): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $company = Mockery::mock(Companies::class)->makePartial();
        $company->timezone = 'America/New_York';
        $company->shouldReceive('get')->with('work_hours')->andReturn([
            'opens_at_local' => '09:00', 'closes_at_local' => '17:00',
        ]);
        $company->shouldReceive('get')->with('working_days')->andReturn(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']);
        $company->shouldReceive('get')->with('working_holiday_days')->andReturn([]);
        $lead = Mockery::mock(Lead::class)->makePartial();
        $lead->setRelation('company', $company);
        $lead->shouldReceive('isAiSupport')->once()->andReturn($support);

        $activity = new ReflectionClass(SalesAssistAgentReachOutActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SalesAssistAgentReachOutActivity::class, 'shouldDeferDelivery');
        $this->assertSame($deferred, $method->invoke($activity, $lead));
    }

    public function testGenericActivityKeepsExistingPolicy(): void
    {
        $activity = new ReflectionClass(AgentReachOutActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AgentReachOutActivity::class, 'shouldDeferDelivery');
        $this->assertNull($method->invoke($activity, new Lead()));
    }
}
