<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateEngagementPageTool;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class CreateEngagementPageToolTest extends TestCase
{
    public function testCreatesEngagementPageAndReturnsUnsentActionLink(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agentUser = Users::factory()->create();
        $agent = $this->makeAgent($app, $company->getId(), $agentUser->getId());
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
        $message = new Message([
            'message' => [
                'action_link' => 'https://short.example/vehicle-page',
            ],
        ]);
        $message->id = 456;
        $engagement = new Engagement([
            'uuid' => '019c166e-c115-7000-8000-000000000001',
            'slug' => 'view-vehicle',
        ]);
        $engagement->id = 123;
        $engagement->setRelation('message', $message);

        $tool = new class ($agent, $engagement) extends CreateEngagementPageTool {
            public ?EngagementData $receivedData = null;

            public function __construct(Agent $agent, private readonly Engagement $engagementResult)
            {
                parent::__construct($agent);
            }

            protected function createEngagement(EngagementData $data): Engagement
            {
                $this->receivedData = $data;

                return $this->engagementResult;
            }
        };
        $result = $tool->__invoke(
            lead_id: $lead->getId(),
            action: 'view-vehicle',
            data: [
                'products' => [
                    ['id' => 'vehicle-1', 'interested' => true],
                ],
            ],
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('https://short.example/vehicle-page', $result['action_link']);
        $this->assertSame('not_sent', $result['delivery']);
        $this->assertSame(123, $result['engagement_id']);
        $this->assertSame(456, $result['message_id']);
        $this->assertSame($lead->getId(), $tool->receivedData?->lead->getId());
        $this->assertSame($agentUser->getId(), $tool->receivedData?->user->getId());
        $this->assertNotSame($user->getId(), $tool->receivedData?->user->getId());
        $this->assertSame('view-vehicle', $tool->receivedData?->action);
        $this->assertSame(ActionStatusEnum::SENT, $tool->receivedData?->status);
        $this->assertSame('agent', $tool->receivedData?->source);
        $this->assertSame('agent', $tool->receivedData?->via);
        $this->assertTrue($tool->receivedData?->data['products'][0]['interested']);
    }

    public function testRejectsMissingActionAndUnknownTenantLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = $this->makeAgent($app, $company->getId(), $user->getId());
        $tool = new CreateEngagementPageTool($agent);

        $missingAction = $tool->__invoke(1, '   ');
        $missingLead = $tool->__invoke(PHP_INT_MAX, 'view-vehicle');

        $this->assertSame('error', $missingAction['status']);
        $this->assertSame('error', $missingLead['status']);
    }

    public function testReportsMissingGeneratedActionLink(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = $this->makeAgent($app, $company->getId(), $user->getId());
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
        $engagement = new Engagement([
            'uuid' => '019c166e-c115-7000-8000-000000000002',
            'slug' => 'view-vehicle',
        ]);
        $engagement->id = 124;
        $engagement->setRelation('message', new Message(['message' => []]));

        $tool = new class ($agent, $engagement) extends CreateEngagementPageTool {
            public function __construct(Agent $agent, private readonly Engagement $engagementResult)
            {
                parent::__construct($agent);
            }

            protected function createEngagement(EngagementData $data): Engagement
            {
                return $this->engagementResult;
            }
        };
        $result = $tool->__invoke($lead->getId(), 'view-vehicle');

        $this->assertSame('error', $result['status']);
        $this->assertSame(124, $result['engagement_id']);
    }

    private function makeAgent(Apps $app, int $companyId, int $userId): Agent
    {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($companyId)
            ->create(['user_id' => $userId]);
    }
}
