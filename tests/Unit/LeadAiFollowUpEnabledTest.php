<?php

declare(strict_types=1);

namespace Tests\Unit;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Both follow-up engines gate on this helper, so the accepted shapes are pinned:
 * only an explicit ON counts. A missing field, OFF, or a NO_FOLLOW_UP type all disable.
 */
final class LeadAiFollowUpEnabledTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testOnlyExplicitOnValuesEnable(): void
    {
        $cases = [
            [FollowUpValueEnum::ON()->value, true],
            [FollowUpTypeEnum::LEAD_FOLLOW_UP->value, true],
            [true, true],
            ['1', true],
            ['true', true],
            [null, false],
            ['', false],
            [FollowUpValueEnum::OFF()->value, false],
            [false, false],
            ['0', false],
            ['false', false],
            ['off', false],
            [FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP->value, false],
            [FollowUpTypeEnum::NO_FOLLOW_UP->value, false],
        ];

        foreach ($cases as [$stored, $expected]) {
            $lead = Mockery::mock(Lead::class)->makePartial();
            $lead->shouldReceive('get')->with(IntelligenceModeEnum::AI_FOLLOW_UP->value)->once()->andReturn($stored);

            $this->assertSame($expected, $lead->isAiFollowUpEnabled(), json_encode($stored));
        }
    }
}
