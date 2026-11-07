<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use Kanvas\Intelligence\Agents\Services\AgentProviderService;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Mockery;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;
use Override;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

/**
 * A voice note on a model that cannot listen is transcribed by the audio provider; on one that can,
 * the agent's own model hears it. Without any audio provider the note yields nothing rather than a
 * silently dropped block.
 */
final class AttachmentAudioTranscriptionTest extends TestCase
{
    private const string MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00\xff\xfb\x90\x00";

    public function testGeminiHearsAudioAndTheGenericFakeDoesNot(): void
    {
        $this->assertTrue(AttachmentDescriptionService::providerHearsAudio(new KanvasGemini(key: 'k', model: 'gemini-test')));
        $this->assertFalse(AttachmentDescriptionService::providerHearsAudio(new FakeNeuronProvider('x')));
    }

    public function testTheAudioProviderReusesTheAgentsOwnGeminiWithoutAppCredentials(): void
    {
        $own = new KanvasGemini(key: 'agent-key', model: 'gemini-test');

        $this->assertSame($own, AgentProviderService::audioProvider(new Agent(), $own));
    }

    public function testTheAudioFallbackBuildsTheRealGeminiProvider(): void
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')->andReturnUsing(static fn (string $key): ?string => match ($key) {
            ConfigurationEnum::GEMINI_KEY->value => 'test-gemini-key',
            ConfigurationEnum::GEMINI_MODEL->value => 'gemini-test',
            default => null,
        });
        $agent = new Agent();
        $agent->setRelation('app', $app);

        $provider = AgentProviderService::audioProvider($agent, new FakeNeuronProvider());

        $this->assertInstanceOf(KanvasGemini::class, $provider);
        $this->assertSame('gemini-test', $provider->getModel());
    }

    public function testTheAudioFallbackIsUnavailableWithoutATenantKeyOrTrial(): void
    {
        config(['kanvas.agents.trial_llm_days' => 0]);
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')->andReturn(null);
        $agent = new Agent();
        $agent->setRelation('app', $app);

        $this->assertNull(AgentProviderService::audioProvider($agent, new FakeNeuronProvider()));
    }

    public function testADeafProviderHandsTheVoiceNoteToTheAudioProvider(): void
    {
        $deaf = $this->recordingProvider('never asked');
        $ears = $this->recordingProvider('turn right at the gas station');

        $describer = new AttachmentDescriptionService($deaf, audioProvider: $ears);

        $this->assertSame('turn right at the gas station', $describer->transcribeAudio(self::MP3, 'audio/mpeg'));
        $this->assertSame('Audio: turn right at the gas station', $describer->describeBinary(self::MP3, 'audio/mpeg'));
        $this->assertSame([], $deaf->received);
        $this->assertInstanceOf(AudioContent::class, $ears->received[0]->getContentBlocks()[1]);
    }

    public function testNoAudioProviderMeansNoTranscriptNotAnException(): void
    {
        $describer = new AttachmentDescriptionService($this->recordingProvider('never asked'));

        $this->assertSame('', $describer->transcribeAudio(self::MP3, 'audio/mpeg'));
    }

    public function testLiveTranscriptsKeepTheEndingWhileMemoryDescriptionsStayBounded(): void
    {
        $transcript = str_repeat('Please check the delivery details. ', 25) . 'Deliver on Friday, not Monday.';
        $own = $this->recordingProvider('never asked');
        $ears = $this->recordingProvider($transcript);
        $describer = new AttachmentDescriptionService($own, audioProvider: $ears);

        $this->assertSame($transcript, $describer->transcribeAudio(self::MP3, 'audio/mpeg'));
        $this->assertSame('Audio: ' . mb_substr($transcript, 0, 599) . '…', $describer->describeBinary(self::MP3, 'audio/mpeg'));
    }

    public function testAnImageStillGoesToTheAgentsOwnProvider(): void
    {
        $own = $this->recordingProvider('a white Jeep');
        $ears = $this->recordingProvider('never asked');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $this->assertSame('Image: a white Jeep', new AttachmentDescriptionService($own, audioProvider: $ears)->describeBinary($png, 'image/png'));
        $this->assertSame([], $ears->received);
    }

    private function recordingProvider(string $answer): FakeNeuronProvider
    {
        return new class ($answer) extends FakeNeuronProvider {
            /** @var Message[] */
            public array $received = [];

            #[Override]
            public function chat(Message ...$messages): ProviderResponse
            {
                $this->received = [...$this->received, ...$messages];

                return new ProviderResponse(message: new AssistantMessage($this->response));
            }
        };
    }
}
