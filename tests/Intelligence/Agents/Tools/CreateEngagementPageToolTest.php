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
use Throwable;

class CreateEngagementPageToolTest extends TestCase
{
    public function testCreatesGenericEngagementPageAndReturnsUnsentActionLink(): void
    {
        $app = app(Apps::class);
        $requestingUser = auth()->user();
        $company = $requestingUser->getCurrentCompany();
        $agentUser = Users::factory()->create();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $agentUser->getId()]);
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
        $tool->withContext($app, $company, $requestingUser);

        $result = $tool->__invoke(
            lead_id: $lead->getId(),
            action: 'credit-app',
            data: [
                'campaign' => 'finance-follow-up',
            ],
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('https://short.example/vehicle-page', $result['action_link']);
        $this->assertSame('not_sent', $result['delivery']);
        $this->assertSame(123, $result['engagement_id']);
        $this->assertSame(456, $result['message_id']);
        $this->assertSame($lead->getId(), $tool->receivedData?->lead->getId());
        $this->assertSame('credit-app', $tool->receivedData?->action);
        $this->assertSame(ActionStatusEnum::SENT, $tool->receivedData?->status);
        $this->assertSame('agent', $tool->receivedData?->source);
        $this->assertSame('agent', $tool->receivedData?->via);
        $this->assertSame($agentUser->getId(), $tool->receivedData?->user->getId());
        $this->assertNotSame($requestingUser->getId(), $tool->receivedData?->user->getId());
        $this->assertSame('finance-follow-up', $tool->receivedData?->data['campaign']);
    }

    public function testRejectsMissingActionAndUnknownTenantLead(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
        $tool = new CreateEngagementPageTool($agent);
        $tool->withContext($app, $company, $user);

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
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
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
        $tool->withContext($app, $company, $user);

        $result = $tool->__invoke($lead->getId(), 'credit-app');

        $this->assertSame('error', $result['status']);
        $this->assertSame(124, $result['engagement_id']);
    }

    public function testAcceptsNullDataForActionsWithoutPayload(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
        $message = new Message(['message' => ['action_link' => 'https://short.example/credit-app']]);
        $engagement = new Engagement(['uuid' => '019c166e-c115-7000-8000-000000000003']);
        $engagement->id = 125;
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
        $tool->withContext($app, $company, $user);

        $result = $tool->__invoke($lead->getId(), 'credit-app', null);

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $tool->receivedData?->data);
    }

    public function testViewVehicleRequiresProductIds(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
        $tool = new CreateEngagementPageTool($agent);
        $tool->withContext($app, $company, $user);

        $result = $tool->__invoke($lead->getId(), 'view-vehicle', []);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('data.product_id', $result['message']);
    }

    public function testViewVehicleRejectsUnknownTenantVariant(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
        $tool = new CreateEngagementPageTool($agent);
        $tool->withContext($app, $company, $user);

        $result = $tool->__invoke($lead->getId(), 'view-vehicle', [
            'product_id' => [PHP_INT_MAX],
        ]);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('was not found', $result['message']);
    }

    public function testReportsEngagementCreationFailureWithSafeContext(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
        $exception = new \RuntimeException('Action Engine failed');

        $tool = new class ($agent, $exception) extends CreateEngagementPageTool {
            public ?Throwable $reportedException = null;
            public ?int $reportedLeadId = null;
            public ?string $reportedAction = null;

            public function __construct(Agent $agent, private readonly Throwable $exception)
            {
                parent::__construct($agent);
            }

            protected function createEngagement(EngagementData $data): Engagement
            {
                throw $this->exception;
            }

            protected function reportException(Throwable $exception, int $leadId, string $action): void
            {
                $this->reportedException = $exception;
                $this->reportedLeadId = $leadId;
                $this->reportedAction = $action;
            }
        };
        $tool->withContext($app, $company, $user);

        $result = $tool->__invoke($lead->getId(), 'credit-app');

        $this->assertSame('error', $result['status']);
        $this->assertSame($exception, $tool->reportedException);
        $this->assertSame($lead->getId(), $tool->reportedLeadId);
        $this->assertSame('credit-app', $tool->reportedAction);
    }
}
