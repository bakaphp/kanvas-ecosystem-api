<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Kanvas\NervousSystem\Orchestrator\Signals\Adapters\PlainTranscriptSignalAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Tests\TestCase;

class PlainTranscriptSignalAdapterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $text = "Team Daily\nFri, Jul 24, 2026\n\n"
            . "0:08 - Speaker One\nGood morning everyone, ready to start.\n\n"
            . "0:36 - Unidentified Speaker\nMorning.\n\n"
            . "0:42 - Speaker Two\nLet's review the Salesforce cleanup progress.\n\n"
            . "1:00 - Speaker One\nYes, the dedup agent is next week.\n";

        return ['type' => 'transcript', 'transcript' => $text];
    }

    public function testNormalizesPlainTimestampedTranscript(): void
    {
        $signal = new PlainTranscriptSignalAdapter()->parse($this->payload());

        $this->assertSame(SignalSourceEnum::PLAIN_TRANSCRIPT, $signal->source);
        $this->assertSame(ProjectIngestTypeEnum::TRANSCRIPT, $signal->kind);
        // Preamble's first line becomes the title; the date line is not a cue.
        $this->assertSame('Team Daily', $signal->title);
        $this->assertStringContainsString('Speaker One: Good morning everyone, ready to start.', $signal->content);
        $this->assertStringContainsString("Speaker Two: Let's review the Salesforce cleanup progress.", $signal->content);
        $this->assertStringNotContainsString('Fri, Jul 24, 2026', $signal->content);
        $this->assertStringNotContainsString('0:08 -', $signal->content);
    }

    public function testActorsExcludeUnidentifiedSpeakerAndAreUnique(): void
    {
        $signal = new PlainTranscriptSignalAdapter()->parse($this->payload());

        $names = array_map(fn ($a) => $a['name'], $signal->actors);
        $this->assertSame(['Speaker One', 'Speaker Two'], $names);
        $this->assertSame([], $signal->actorEmails());
    }

    public function testEnumResolvesPlainTranscriptAdapter(): void
    {
        $this->assertInstanceOf(
            PlainTranscriptSignalAdapter::class,
            SignalSourceEnum::PLAIN_TRANSCRIPT->adapter(),
        );
    }

    public function testEmptyTranscriptYieldsEmptyContent(): void
    {
        $signal = new PlainTranscriptSignalAdapter()->parse(['type' => 'transcript', 'transcript' => '']);

        $this->assertSame('', $signal->content);
        $this->assertSame([], $signal->actors);
        $this->assertSame('Untitled meeting', $signal->title);
    }
}
