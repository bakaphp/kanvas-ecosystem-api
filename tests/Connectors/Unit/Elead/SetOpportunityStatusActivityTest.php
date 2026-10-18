<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Elead;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
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

    public function testUpdateSubStatusPostsTheSubStatusAndInvalidatesOpportunityCache(): void
    {
        $app = Mockery::mock(Apps::class);
        $company = Mockery::mock(Companies::class);
        $company->shouldReceive('getId')->once()->andReturn(123);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with(
                '/sales/v2/elead/opportunities/opportunity-456/subStatus/update',
                ['subStatus' => 'Contact Made']
            )
            ->andReturn([]);

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

        $lead->updateSubStatus('Contact Made');
    }

    public function testRecognisesElead400InvalidSubStatus(): void
    {
        $request = new Request('POST', '/sales/v2/elead/opportunities/opportunity-456/subStatus/update');
        $invalid = new ClientException(
            'Bad Request',
            $request,
            new Response(400, [], '{"code":"InvalidSubStatus","message":"Invalid Opportunity SubStatus."}')
        );
        $notFound = new ClientException(
            'Not Found',
            $request,
            new Response(404, [], 'The resource you are looking for has been removed')
        );

        $this->assertTrue(EleadLead::isInvalidSubStatusResponse($invalid));
        $this->assertFalse(EleadLead::isInvalidSubStatusResponse($notFound));
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
