<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Kanvas\NervousSystem\Orchestrator\Routing\Actions\SegmentSignalAction;
use Kanvas\NervousSystem\Orchestrator\Signals\DataTransferObject\InboundSignal;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class SegmentSignalActionTest extends TestCase
{
    private function signal(string $content): InboundSignal
    {
        return new InboundSignal(
            source: SignalSourceEnum::READ_AI,
            kind: ProjectIngestTypeEnum::TRANSCRIPT,
            externalId: 'sess_x',
            title: 'Sync',
            content: $content,
            occurredAt: null,
            actors: [['name' => 'A', 'email' => 'a@x.io']],
        );
    }

    public function testShortSignalSkipsSegmentationAndReturnsItself(): void
    {
        $signal = $this->signal('Too short to be multi-topic.');

        $segments = new SegmentSignalAction($signal)->execute();

        $this->assertCount(1, $segments);
        $this->assertSame($signal, $segments[0]);
    }

    public function testSplitsLongSignalIntoDerivedSegments(): void
    {
        StructuredAnonymousAgent::fake([
            ['segments' => [
                ['title' => 'Topic A', 'content' => 'All about A.'],
                ['title' => 'Topic B', 'content' => 'All about B.'],
            ]],
        ]);

        $signal = $this->signal(str_repeat('Long multi-topic content here. ', 60));

        $segments = new SegmentSignalAction($signal)->execute();

        $this->assertCount(2, $segments);
        $this->assertSame('Topic A', $segments[0]->title);
        $this->assertSame('sess_x#seg-1', $segments[0]->externalId);
        $this->assertSame('sess_x#seg-2', $segments[1]->externalId);
        // Actors stay parent-wide on every segment (topic split, not attendee split).
        $this->assertSame(['a@x.io'], $segments[0]->actorEmails());
    }

    public function testSingleSegmentResponseCollapsesToWholeSignal(): void
    {
        StructuredAnonymousAgent::fake([
            ['segments' => [['title' => 'Only', 'content' => 'One topic.']]],
        ]);

        $signal = $this->signal(str_repeat('Long single-topic content. ', 60));

        $segments = new SegmentSignalAction($signal)->execute();

        $this->assertCount(1, $segments);
        $this->assertSame($signal, $segments[0]);
    }
}
