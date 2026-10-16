<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Elead;

use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Connectors\Elead\Entities\Lead as EleadLead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Workflow\SetOpportunityStatusActivity;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\StoredWorkflow;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class SetOpportunityStatusActivityTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testUpdateStatusSendsStatusPayloadAndInvalidatesOpportunityCache(): void
    {
        $app = Mockery::mock(Apps::class);
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('getId')->once()->andReturn(123);

        $client = Mockery::mock(Client::class);
        $response = ['status' => 'Active', 'subStatus' => 'Engaged'];
        $client->shouldReceive('put')
            ->once()
            ->with(
                '/sales/v2/elead/opportunities/opportunity-456/status',
                ['status' => 'Active', 'subStatus' => 'Engaged']
            )
            ->andReturn($response);

        $lead = new class () extends EleadLead {
            public Client $clientMock;

            protected function client(): Client
            {
                return $this->clientMock;
            }
        };
        $lead->id = 'opportunity-456';
        $lead->app = $app;
        $lead->company = $company;
        $lead->clientMock = $client;

        Redis::shouldReceive('del')
            ->once()
            ->with('elead:123:lead:opportunity-456');

        $this->assertSame($response, $lead->updateStatus('Active', 'Engaged'));
    }

    public function testActivityReturnsGracefullyWhenEntityHasNoLead(): void
    {
        $app = app(Apps::class);
        $message = Mockery::mock(Message::class)->makePartial();
        $message->shouldReceive('getId')->andReturn(789);
        $message->shouldReceive('entity')->with(Lead::class)->andReturn(null);

        $result = $this->activity()->execute($message, $app, []);

        $this->assertSame([
            'error' => 'No Lead found for this entity',
            'entity_id' => 789,
        ], $result);
    }

    public function testActivityReturnsGracefullyWhenCompanyIsNotConfiguredForElead(): void
    {
        $app = app(Apps::class);
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('get')->once()->with(CustomFieldEnum::COMPANY->value)->andReturn(null);

        $lead = Mockery::mock(Lead::class)->makePartial();
        $lead->company = $company;
        $lead->shouldReceive('getId')->andReturn(101);

        $result = $this->activity()->execute($lead, $app, []);

        $this->assertSame([
            'error' => 'Company not found in Elead',
            'lead_id' => 101,
        ], $result);
    }

    private function activity(): SetOpportunityStatusActivity
    {
        return new SetOpportunityStatusActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
    }
}
