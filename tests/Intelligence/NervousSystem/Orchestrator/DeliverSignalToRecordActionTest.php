<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Intelligence\Agents\Jobs\WakeAgentInSessionJob;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\DeliverSignalToRecordAction;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingCandidate;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\SignalDeliveryStatusEnum;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Kanvas\Social\Messages\Models\Message;
use Tests\Intelligence\NervousSystem\Orchestrator\Concerns\MakesMeetingRoutingFixtures;
use Tests\TestCase;

class DeliverSignalToRecordActionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesMeetingRoutingFixtures;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social', 'workflow'];

    public function testALeadMeetingIsWrittenAsANoteAndWakesTheSalesManager(): void
    {
        Bus::fake([WakeAgentInSessionJob::class]);

        $company = $this->freshCompany();
        $salesManager = $this->agentOfType($company, SalesManagerAgent::class);
        $lead = $this->lead($company, $this->person($company, $this->uniqueEmail()));
        $signal = $this->meetingSignal([]);

        $result = new DeliverSignalToRecordAction(RoutingCandidate::forLead($lead), $signal)->execute();

        $this->assertSame(SignalDeliveryStatusEnum::DELIVERED, $result->status);
        $this->assertSame($salesManager->getId(), $result->agent?->getId());

        $note = Message::query()->find($result->messageId);
        $this->assertNotNull($note);
        $this->assertStringContainsString('we need the pro plan by March', (string) $note->message['content']);
        $this->assertStringContainsString('Send quote Friday', (string) $note->message['content']);

        $this->assertStringStartsWith('meeting-' . $signal->source->value . '-lead-' . $lead->getId() . '-', (string) $note->slug);

        Bus::assertDispatched(
            WakeAgentInSessionJob::class,
            fn (WakeAgentInSessionJob $job): bool => $job->agent->getId() === $salesManager->getId()
                && str_contains($job->instruction, 'read_message_content(message_id: ' . $result->messageId . ')')
                && str_contains($job->instruction, 'add_lead_note')
        );
    }

    public function testTheSameMeetingTwiceIsDeliveredOnce(): void
    {
        Bus::fake([WakeAgentInSessionJob::class]);

        $company = $this->freshCompany();
        $this->agentOfType($company, SalesManagerAgent::class);
        $lead = $this->lead($company, $this->person($company, $this->uniqueEmail()));
        $signal = $this->meetingSignal([]);

        $first = new DeliverSignalToRecordAction(RoutingCandidate::forLead($lead), $signal)->execute();
        $again = new DeliverSignalToRecordAction(RoutingCandidate::forLead($lead), $signal)->execute();

        $this->assertSame(SignalDeliveryStatusEnum::DUPLICATE, $again->status);
        Bus::assertDispatchedTimes(WakeAgentInSessionJob::class, 1);
        $this->assertNull($again->messageId);
        $this->assertSame(1, Message::query()->where('slug', Message::query()->find($first->messageId)->slug)->count());
    }

    public function testADeletedTranscriptNoteStillBlocksARedelivery(): void
    {
        Bus::fake([WakeAgentInSessionJob::class]);

        $company = $this->freshCompany();
        $this->agentOfType($company, SalesManagerAgent::class);
        $lead = $this->lead($company, $this->person($company, $this->uniqueEmail()));
        $signal = $this->meetingSignal([]);

        $first = new DeliverSignalToRecordAction(RoutingCandidate::forLead($lead), $signal)->execute();
        Message::query()->find($first->messageId)->delete();

        $again = new DeliverSignalToRecordAction(RoutingCandidate::forLead($lead), $signal)->execute();

        $this->assertSame(SignalDeliveryStatusEnum::DUPLICATE, $again->status);
        Bus::assertDispatchedTimes(WakeAgentInSessionJob::class, 1);
    }

    public function testTheWordingFollowsTheSignalKind(): void
    {
        Bus::fake([WakeAgentInSessionJob::class]);

        $company = $this->freshCompany();
        $this->agentOfType($company, SalesManagerAgent::class);
        $lead = $this->lead($company, $this->person($company, $this->uniqueEmail()));

        $email = new InboundSignal(
            source: SignalSourceEnum::PLAIN_TRANSCRIPT,
            kind: ProjectIngestTypeEnum::EMAIL,
            externalId: 'msg_' . uniqid(),
            title: 'Re: quote',
            content: 'Can you resend the quote?',
            occurredAt: null,
            actors: [],
        );

        $result = new DeliverSignalToRecordAction(RoutingCandidate::forLead($lead), $email)->execute();

        $note = Message::query()->find($result->messageId);
        $this->assertStringStartsWith('email-', (string) $note->slug);
        $this->assertStringContainsString('Email: "Re: quote"', (string) $note->message['content']);
        $this->assertStringContainsString("Body:\nCan you resend the quote?", (string) $note->message['content']);

        Bus::assertDispatched(
            WakeAgentInSessionJob::class,
            fn (WakeAgentInSessionJob $job): bool => str_starts_with($job->instruction, 'A new email was just routed to the lead')
                && str_contains($job->instruction, 'Its full body is message_id ' . $result->messageId)
        );
    }

    public function testNoAgentMeansNothingIsWritten(): void
    {
        Bus::fake([WakeAgentInSessionJob::class]);

        $company = $this->freshCompany();
        $lead = $this->lead($company, $this->person($company, $this->uniqueEmail()));

        $result = new DeliverSignalToRecordAction(RoutingCandidate::forLead($lead), $this->meetingSignal([]))->execute();

        $this->assertSame(SignalDeliveryStatusEnum::NO_AGENT, $result->status);
        $this->assertNull($result->messageId);
        Bus::assertNotDispatched(WakeAgentInSessionJob::class);
    }

    public function testAnOrganizationMeetingGoesToThePmWithTheOrganizationNoteTool(): void
    {
        Bus::fake([WakeAgentInSessionJob::class]);

        $company = $this->freshCompany();
        $pm = $this->agentOfType($company, ProjectManagerAgent::class);
        $organization = $this->organization($company, 'Acme');

        $result = new DeliverSignalToRecordAction(RoutingCandidate::forOrganization($organization), $this->meetingSignal([]))->execute();

        $this->assertSame(SignalDeliveryStatusEnum::DELIVERED, $result->status);
        Bus::assertDispatched(
            WakeAgentInSessionJob::class,
            fn (WakeAgentInSessionJob $job): bool => $job->agent->getId() === $pm->getId()
                && str_contains($job->instruction, 'add_organization_note')
        );
    }
}
