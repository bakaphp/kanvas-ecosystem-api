<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Kanvas\NervousSystem\Orchestrator\Signals\Adapters\ReadAiSignalAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\Contracts\SignalSourceAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Tests\TestCase;

class SignalSourceTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function readAiPayload(): array
    {
        return [
            'trigger' => 'meeting_end',
            'session_id' => 'sess_123',
            'title' => 'Acme onboarding sync',
            'start_time' => '2026-07-25T15:00:00Z',
            // Read.ai sends `owner` separately; here it duplicates a participant to exercise dedup.
            'owner' => ['name' => 'Max', 'email' => 'max@ourco.com'],
            'participants' => [
                ['name' => 'Max', 'email' => 'max@ourco.com'],
                ['name' => 'Greg', 'email' => 'greg@acme.io'],
                ['name' => 'No Email'],
            ],
            'topics' => [
                ['text' => 'Kickoff scope'],
                ['text' => 'Integration timeline'],
            ],
            'action_items' => [
                ['text' => 'Send SOW to Acme'],
                'Follow up Monday',
            ],
            'transcript' => [
                'speaker_blocks' => [
                    ['speaker' => ['name' => 'Max'], 'words' => 'Welcome everyone.'],
                    ['speaker' => ['name' => 'Greg'], 'words' => 'Thanks, excited to start.'],
                ],
            ],
        ];
    }

    public function testReadAiAdapterNormalizesToInboundSignal(): void
    {
        $signal = new ReadAiSignalAdapter()->parse($this->readAiPayload());

        $this->assertSame(SignalSourceEnum::READ_AI, $signal->source);
        $this->assertSame(ProjectIngestTypeEnum::TRANSCRIPT, $signal->kind);
        $this->assertSame('sess_123', $signal->externalId);
        $this->assertSame('Acme onboarding sync', $signal->title);
        $this->assertSame('2026-07-25T15:00:00Z', $signal->occurredAt);
        $this->assertCount(3, $signal->actors);
        $this->assertStringContainsString('Max: Welcome everyone.', $signal->content);
        $this->assertStringContainsString('Greg: Thanks, excited to start.', $signal->content);
        $this->assertSame(['Kickoff scope', 'Integration timeline'], $signal->metadata['topics']);
        $this->assertSame(['Send SOW to Acme', 'Follow up Monday'], $signal->metadata['action_items']);
        $this->assertSame('meeting_end', $signal->metadata['trigger']);
    }

    public function testOwnerIsFoldedIntoActorsAndDedupedByEmail(): void
    {
        $payload = $this->readAiPayload();
        // Distinct owner not present in participants → must appear as an actor.
        $payload['owner'] = ['name' => 'Ceo', 'email' => 'ceo@ourco.com'];

        $signal = new ReadAiSignalAdapter()->parse($payload);

        $this->assertContains('ceo@ourco.com', $signal->actorEmails());
        // Owner leads; participant emails follow; no duplicates.
        $this->assertSame(['ceo@ourco.com', 'max@ourco.com', 'greg@acme.io'], $signal->actorEmails());
    }

    public function testSpeakerAsBareStringDoesNotFatal(): void
    {
        $payload = $this->readAiPayload();
        $payload['transcript']['speaker_blocks'] = [
            ['speaker' => 'Max', 'words' => 'Bare string speaker.'],
            ['speaker' => ['name' => 'Greg'], 'words' => 'Object speaker.'],
        ];

        $signal = new ReadAiSignalAdapter()->parse($payload);

        $this->assertStringContainsString('Max: Bare string speaker.', $signal->content);
        $this->assertStringContainsString('Greg: Object speaker.', $signal->content);
    }

    public function testInboundSignalExtractsActorEmailsAndDomains(): void
    {
        $signal = new ReadAiSignalAdapter()->parse($this->readAiPayload());

        // The no-email actor is dropped; emails are lowercased + deduped.
        $this->assertSame(['max@ourco.com', 'greg@acme.io'], $signal->actorEmails());
        $this->assertSame(['ourco.com', 'acme.io'], $signal->actorDomains());
    }

    public function testUnexpectedShapeDegradesGracefully(): void
    {
        $signal = new ReadAiSignalAdapter()->parse(['session_id' => 'x']);

        $this->assertSame('x', $signal->externalId);
        $this->assertSame('Untitled meeting', $signal->title);
        $this->assertNull($signal->occurredAt);
        $this->assertSame([], $signal->actors);
        $this->assertSame('', $signal->content);
    }

    public function testEnumResolvesItsAdapter(): void
    {
        $this->assertInstanceOf(ReadAiSignalAdapter::class, SignalSourceEnum::READ_AI->adapter());
    }

    public function testEverySourceHasAnAdapter(): void
    {
        // Guardrail: adding a case without an adapter arm would throw \UnhandledMatchError here.
        foreach (SignalSourceEnum::cases() as $source) {
            $this->assertInstanceOf(SignalSourceAdapter::class, $source->adapter());
        }
    }

    public function testParseWithFallbackRespectsThePreferredSourceWhenItMatches(): void
    {
        $signal = SignalSourceEnum::parseWithFallback($this->readAiPayload(), SignalSourceEnum::READ_AI);

        $this->assertNotNull($signal);
        $this->assertSame(SignalSourceEnum::READ_AI, $signal->source);
    }

    public function testParseWithFallbackFallsBackWhenTheConfiguredSourceYieldsEmpty(): void
    {
        // A plain transcript arriving on a receiver pinned to read_ai — read_ai can't parse it, so the
        // probe falls back to the adapter that can.
        $payload = ['type' => 'transcript', 'transcript' => "Standup\nFri\n\n0:05 - Alex\nShipping today.\n"];

        $signal = SignalSourceEnum::parseWithFallback($payload, SignalSourceEnum::READ_AI);

        $this->assertNotNull($signal);
        $this->assertSame(SignalSourceEnum::PLAIN_TRANSCRIPT, $signal->source);
        $this->assertStringContainsString('Alex: Shipping today.', $signal->content);
    }

    public function testParseWithFallbackReturnsNullWhenNoAdapterCanExtractContent(): void
    {
        $this->assertNull(
            SignalSourceEnum::parseWithFallback(['type' => 'transcript', 'transcript' => '']),
        );
    }
}
