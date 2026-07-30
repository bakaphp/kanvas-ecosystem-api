<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Kanvas\NervousSystem\Orchestrator\Signals\Adapters\WebVttSignalAdapter;
use Kanvas\NervousSystem\Orchestrator\Signals\Enums\SignalSourceEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectIngestTypeEnum;
use Tests\TestCase;

class WebVttSignalAdapterTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $vtt = "WEBVTT\r\n\r\n"
            . "00000000-0000-0000-0000-000000000000/5-0\r\n"
            . "00:00:04.956 --> 00:00:09.836\r\n"
            . "<v Speaker One>So now I'm talking and I think it should\r\nstart transcribing,</v>\r\n\r\n"
            . "00000000-0000-0000-0000-000000000000/5-1\r\n"
            . "00:00:09.836 --> 00:00:14.316\r\n"
            . "<v Speaker One>although I don't see anything yet.</v>\r\n\r\n"
            . "00000000-0000-0000-0000-000000000000/6-0\r\n"
            . "00:00:13.516 --> 00:00:16.436\r\n"
            . "<v Speaker Two>We should get an e-mail at the end of the day.</v>\r\n";

        return ['type' => 'transcript', 'transcript' => $vtt];
    }

    public function testNormalizesWebVttToInboundSignal(): void
    {
        $signal = new WebVttSignalAdapter()->parse($this->payload());

        $this->assertSame(SignalSourceEnum::WEBVTT, $signal->source);
        $this->assertSame(ProjectIngestTypeEnum::TRANSCRIPT, $signal->kind);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $signal->externalId);
    }

    public function testMergesConsecutiveSameSpeakerCuesAndStripsMarkup(): void
    {
        $signal = new WebVttSignalAdapter()->parse($this->payload());

        $this->assertStringContainsString(
            "Speaker One: So now I'm talking and I think it should start transcribing, although I don't see anything yet.",
            $signal->content,
        );
        $this->assertStringContainsString('Speaker Two: We should get an e-mail', $signal->content);
        $this->assertStringNotContainsString('-->', $signal->content);
        $this->assertStringNotContainsString('<v ', $signal->content);
        $this->assertStringNotContainsString('WEBVTT', $signal->content);
    }

    public function testActorsAreUniqueVoiceSpeakers(): void
    {
        $signal = new WebVttSignalAdapter()->parse($this->payload());

        $names = array_map(fn ($a) => $a['name'], $signal->actors);
        $this->assertSame(['Speaker One', 'Speaker Two'], $names);
        $this->assertSame([], $signal->actorEmails());
    }

    public function testEnumResolvesWebVttAdapter(): void
    {
        $this->assertInstanceOf(WebVttSignalAdapter::class, SignalSourceEnum::WEBVTT->adapter());
    }

    public function testEmptyTranscriptYieldsEmptyContent(): void
    {
        $signal = new WebVttSignalAdapter()->parse(['type' => 'transcript', 'transcript' => 'WEBVTT']);

        $this->assertSame('', $signal->content);
        $this->assertSame([], $signal->actors);
        $this->assertSame('', $signal->externalId);
    }
}
