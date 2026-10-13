<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpOutcomeKindEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class LeadTypeContinuedFollowUpTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testDefaultsRespectStatePrefixAndBooleanValues(): void
    {
        $service = new LeadConfigurationService();

        foreach (['phone_', 'internet_', 'showroom_', ''] as $prefix) {
            foreach (['active', 'cns', 'closed-sold'] as $state) {
                foreach ([0, false, '0', 'false', 'off', 1, true, '1', 'true', 'on'] as $value) {
                    $config = [
                        $prefix . 'con_fu_active_default' => 1,
                        $prefix . 'con_fu_cns_default' => 1,
                        $prefix . 'con_fu_closed-sold_default' => 1,
                        $prefix . 'first_fu_active_default' => false,
                    ];
                    $config[$prefix . 'con_fu_' . $state . '_default'] = $value;

                    $this->assertSame(
                        in_array($value, [1, true, '1', 'true', 'on'], true),
                        $service->allowsContinuedFollowUp($this->lead($config, $state)),
                        $prefix . $state . ': ' . json_encode($value),
                    );
                }
            }
        }
    }

    public function testMissingTypeOrSettingAllowsFollowUp(): void
    {
        foreach ([null, [], ['phone_first_fu_active_default' => false], ['phone_con_fu_cns_default' => 0]] as $config) {
            $this->assertTrue(new LeadConfigurationService()->allowsContinuedFollowUp($this->lead($config)));
        }

        $this->assertTrue(new LeadConfigurationService()->allowsContinuedFollowUp(
            $this->lead(['phone_con_fu_active_default' => 0], 'cns')
        ));
    }

    public function testActionSkipsBeforeRetryAndSendEvenWhenForced(): void
    {
        $cases = [
            [0, 1, 'lead_follow_up_disabled'],
            [false, 1, 'lead_follow_up_disabled'],
            ['0', 1, 'lead_follow_up_disabled'],
            ['false', 1, 'lead_follow_up_disabled'],
            ['off', 1, 'lead_follow_up_disabled'],
            [null, 0, 'lead_type_follow_up_disabled'],
            [1, 0, 'lead_type_follow_up_disabled'],
            [null, 1, 'exhausted'],
            [1, 1, 'exhausted'],
            [true, 1, 'exhausted'],
            ['1', 1, 'exhausted'],
        ];

        foreach ([false, true] as $force) {
            foreach ($cases as [$leadSetting, $typeSetting, $reason]) {
                $lead = $this->lead(['phone_con_fu_active_default' => $typeSetting]);
                $lead->shouldReceive('get')->with('ai_follow_up')->once()->andReturn($leadSetting);
                if ($reason === 'exhausted') {
                    // Reaching this existing gate proves an absent/enabled lead setting
                    // passes both new gates without invoking any outbound transport.
                    $lead->shouldReceive('isFollowUpExhausted')->once()->andReturn(true);
                } else {
                    $lead->shouldNotReceive('isFollowUpExhausted');
                }
                $stage = Mockery::mock(PipelineStage::class);
                $stage->shouldReceive('offsetExists')->with('config')->andReturn(true);
                $stage->shouldReceive('getAttribute')->with('config')->andReturn([
                    'follow_up' => ['enabled' => true, 'time_based' => ['interval_minutes' => 1]],
                ]);
                $lead->shouldReceive('getAttribute')->with('stage')->andReturn($stage);
                $lead->shouldReceive('getAttribute')->with('pipeline_stage_id')->andReturn(123);
                $lead->shouldReceive('emitLedgerEvent')->once()->with('lead.follow_up.skipped', EventStatusEnum::INFO, [
                    'stage_id' => 123,
                    'reason' => $reason,
                ]);
                $lead->shouldNotReceive('getFollowUpStateCount');
                $lead->shouldNotReceive('moveToNextPipelineStage');

                $outcome = new FollowUpLeadAction(
                    app: Mockery::mock(Apps::class),
                    company: Mockery::mock(Companies::class),
                    lead: $lead,
                    agent: Mockery::mock(Agent::class),
                    force: $force,
                )->execute();

                $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
                $this->assertSame($reason, $outcome->reason);
            }
        }
    }

    private function lead(?array $config, string $state = 'active'): Lead
    {
        $type = null;
        if ($config !== null) {
            $type = Mockery::mock(LeadType::class);
            $type->shouldReceive('offsetExists')->with('config')->andReturn(true);
            $type->shouldReceive('getAttribute')->with('config')->andReturn($config);
        }

        $relation = Mockery::mock(BelongsTo::class);
        $relation->shouldReceive('first')->andReturn($type);
        $lead = Mockery::mock(Lead::class);
        $lead->shouldReceive('type')->andReturn($relation);
        $lead->shouldReceive('isOpen')->andReturn($state === 'active');
        $lead->shouldReceive('closeNotSold')->andReturn($state === 'cns');
        $lead->shouldReceive('closeSold')->andReturn($state === 'closed-sold');

        return $lead;
    }
}
