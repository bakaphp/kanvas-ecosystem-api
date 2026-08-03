<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateEngagementPageTool;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;
use Throwable;

class CreateEngagementPageToolTest extends TestCase
{
    public function testCreatesEngagementPageAndReturnsUnsentActionLink(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
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

        $tool = new class ($engagement) extends CreateEngagementPageTool {
            public ?EngagementData $receivedData = null;

            public function __construct(private readonly Engagement $engagementResult)
            {
                parent::__construct();
            }

            protected function createEngagement(EngagementData $data): Engagement
            {
                $this->receivedData = $data;

                return $this->engagementResult;
            }
        };
        $tool->withContext($app, $company, $user);

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
        $tool = new CreateEngagementPageTool();
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

        $tool = new class ($engagement) extends CreateEngagementPageTool {
            public function __construct(private readonly Engagement $engagementResult)
            {
                parent::__construct();
            }

            protected function createEngagement(EngagementData $data): Engagement
            {
                return $this->engagementResult;
            }
        };
        $tool->withContext($app, $company, $user);

        $result = $tool->__invoke($lead->getId(), 'view-vehicle');

        $this->assertSame('error', $result['status']);
        $this->assertSame(124, $result['engagement_id']);
    }

    public function testReportsEngagementCreationFailureWithSafeContext(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
        $exception = new \RuntimeException('Action Engine failed');

        $tool = new class ($exception) extends CreateEngagementPageTool {
            public ?Throwable $reportedException = null;
            public ?int $reportedLeadId = null;
            public ?string $reportedAction = null;

            public function __construct(private readonly Throwable $exception)
            {
                parent::__construct();
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

        $result = $tool->__invoke($lead->getId(), 'view-vehicle');

        $this->assertSame('error', $result['status']);
        $this->assertSame($exception, $tool->reportedException);
        $this->assertSame($lead->getId(), $tool->reportedLeadId);
        $this->assertSame('view-vehicle', $tool->reportedAction);
    }
}
