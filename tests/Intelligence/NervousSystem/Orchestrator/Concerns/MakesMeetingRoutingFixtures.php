<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator\Concerns;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\HumanResources\Employees\Enums\EmployeeStatusEnum;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Orchestrator\Webhooks\ProcessOrchestratorSignalJob;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Mockery;

/**
 * A fresh company per test: meeting-agent settings live in Redis, which no transaction rolls back,
 * so a shared company would carry one test's setting into the next.
 */
trait MakesMeetingRoutingFixtures
{
    private function freshCompany(): Companies
    {
        $company = Companies::factory()->create();
        $company->associateUserApp($this->testUser(), app(Apps::class), 1);

        return $company;
    }

    private function testUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }

    private function uniqueEmail(string $domain = 'acme-buyer.io'): string
    {
        return 'p' . Str::lower(Str::random(10)) . '@' . $domain;
    }

    /**
     * @param list<string> $emails
     */
    private function meetingSignal(array $emails, string $title = 'Discovery call'): InboundSignal
    {
        return new InboundSignal(
            source: SignalSourceEnum::READ_AI,
            kind: ProjectIngestTypeEnum::TRANSCRIPT,
            externalId: 'sess_' . Str::random(12),
            title: $title,
            content: 'Buyer: we need the pro plan by March. Rep: I will send a quote Friday.',
            occurredAt: '2026-11-16T15:00:00Z',
            actors: array_map(static fn (string $email): array => ['name' => 'Someone', 'email' => $email], $emails),
            metadata: ['summary' => 'Pricing discussion.', 'action_items' => ['Send quote Friday']],
        );
    }

    private function person(Companies $company, string $email): People
    {
        $app = app(Apps::class);

        /** @var People $person */
        $person = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->testUser()->getId())
            ->create();

        $person->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => $email,
            'weight' => 0,
        ]);

        return $person;
    }

    private function lead(Companies $company, People $person, bool $open = true): Lead
    {
        $app = app(Apps::class);

        $status = LeadStatus::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => ($open ? 'Working ' : 'Lost ') . Str::random(6),
            'is_default' => 0,
            'is_open' => $open,
        ]);

        /** @var Lead $lead */
        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($person->getId())
            ->withUserId($this->testUser()->getId())
            ->create(['leads_status_id' => $status->getId(), 'title' => 'Acme pro plan']);

        return $lead;
    }

    /**
     * @param list<string> $domains
     */
    private function organization(Companies $company, string $name, array $domains = []): Organization
    {
        $organization = Organization::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $this->testUser()->getId(),
            'name' => $name,
        ]);

        if ($domains !== []) {
            $organization->addEmailDomains($domains);
        }

        return $organization;
    }

    private function employee(Companies $company, Users $user, EmployeeStatusEnum $status = EmployeeStatusEnum::ACTIVE): Employee
    {
        /** @var Employee $employee */
        $employee = Employee::withoutEvents(fn (): Employee => Employee::create([
            'uuid' => (string) Str::uuid(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'people_id' => 0,
            'position_id' => 0,
            'status' => $status->value,
            'hired_at' => '2026-01-01',
        ]));

        return $employee;
    }

    /**
     * @param class-string $handler
     */
    private function agentOfType(Companies $company, string $handler, bool $active = true): Agent
    {
        $app = app(Apps::class);

        $type = AgentType::query()->where('handler', $handler)->first()
            ?? AgentType::factory()->withAppId($app->getId())->create(['handler' => $handler, 'provider' => 'neuron']);

        /** @var Agent $agent */
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId(), 'is_active' => $active]);

        return $agent;
    }

    /**
     * Every routed transcript is uploaded, so a test that routes one without this writes to the app's
     * real bucket.
     */
    private function fakeFileStorage(): FilesystemAdapter
    {
        $disk = Storage::fake('signal-files');

        $storage = Storage::partialMock();
        $storage->shouldReceive('build')->andReturn($disk)->byDefault();
        $storage->set(FilesystemServices::PLATFORM_DISK, $disk);

        return $disk;
    }

    private function refuseFileStorage(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put', 'putFileAs')->andReturn(false);

        $storage = Storage::partialMock();
        $storage->shouldReceive('build')->andReturn($disk);
        $storage->set(FilesystemServices::PLATFORM_DISK, $disk);
    }

    private function makeProject(
        Apps $app,
        Companies $company,
        Users $owner,
        string $title
    ): Project {
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $owner->getId(), 'is_active' => true]);

        return new CreateProjectAction(
            ProjectData::from($app, $owner, $company, [
                'title' => $title,
                'objective' => "Deliver {$title}",
                'agent_id' => $agent->id,
            ]),
        )->execute();
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function orchestratorReceiver(
        Apps $app,
        Companies $company,
        Users $user,
        array $configuration
    ): ReceiverWebhook {
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessOrchestratorSignalJob::class],
            ['name' => 'Orchestrator Signal Ingest'],
        );

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $app,
                company: $company,
                user: $user,
                action: $action,
                name: 'Orchestrator ingest',
                description: 'Unlabeled signal routing',
                configuration: $configuration,
                is_active: true,
                run_async: true,
            ),
        )->execute();
    }

    private function callFor(ReceiverWebhook $receiver, array $payload): ReceiverWebhookCall
    {
        return ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $receiver->getId(),
            'uuid' => Str::uuid()->toString(),
            'url' => '/receiver/' . $receiver->uuid,
            'payload' => $payload,
            'raw_payload' => json_encode($payload),
            'status' => 'pending',
        ]);
    }

    /**
     * @param list<string> $emails
     *
     * @return array<string, mixed>
     */
    private function meetingPayload(array $emails): array
    {
        return [
            'session_id' => 'sess_' . Str::random(10),
            'trigger' => 'meeting_end',
            'title' => 'Pricing call',
            'participants' => array_map(static fn (string $email): array => ['name' => 'X', 'email' => $email], $emails),
            'transcript' => ['speaker_blocks' => [['speaker' => ['name' => 'Buyer'], 'words' => 'We want the pro plan.']]],
        ];
    }
}
