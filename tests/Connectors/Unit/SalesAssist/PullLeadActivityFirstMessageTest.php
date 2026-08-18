<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\SalesAssist;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCaseUnit;

final class PullLeadActivityFirstMessageTest extends TestCaseUnit
{
    public function testItTriggersFirstMessageWorkflowWhenNoMessageWasSent(): void
    {
        $app = Mockery::mock(Apps::class);
        $company = Mockery::mock(Companies::class);
        $lead = Mockery::mock(Lead::class);
        $params = ['source' => 'manual-pull'];

        $lead->shouldReceive('get')
            ->once()
            ->with(LeadsConfigurationEnum::SENT_FIRST_MESSAGE_AT->value)
            ->andReturn(null);
        $lead->shouldReceive('getAttribute')
            ->once()
            ->with('company')
            ->andReturn($company);
        $lead->shouldReceive('fireWorkflow')
            ->once()
            ->with(
                WorkflowEnum::FAKE_CONTEXT->value,
                true,
                [
                    'source' => 'manual-pull',
                    'app' => $app,
                    'company' => $company,
                ],
            )
            ->andReturn(null);

        $this->invokeTrigger($lead, $app, $params);
    }

    public function testItDoesNotTriggerFirstMessageWorkflowWhenMessageWasAlreadySent(): void
    {
        $app = Mockery::mock(Apps::class);
        $lead = Mockery::mock(Lead::class);

        $lead->shouldReceive('get')
            ->once()
            ->with(LeadsConfigurationEnum::SENT_FIRST_MESSAGE_AT->value)
            ->andReturn('2026-08-06 10:00:00');
        $lead->shouldNotReceive('fireWorkflow');

        $this->invokeTrigger($lead, $app, []);
    }

    private function invokeTrigger(Lead $lead, Apps $app, array $params): void
    {
        $activity = new ReflectionClass(PullLeadActivity::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PullLeadActivity::class, 'triggerFirstMessageIfNeeded');
        $method->invoke($activity, $lead, $app, $params);
    }
}
