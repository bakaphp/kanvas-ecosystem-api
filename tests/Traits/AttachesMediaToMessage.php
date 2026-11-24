<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Jobs\DescribeMessageAttachmentsJob;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\Stubs\Intelligence\FakeNeuronProvider;

/**
 * Real media on local disk, attached to a message the way every inbound connector attaches it. The
 * url is a local path on purpose: the kernel fetches attachments through AttachmentFetchService,
 * which reads a non-http source straight from disk, so no HTTP fake is needed and the model stub
 * receives a genuine image or audio block.
 */
trait AttachesMediaToMessage
{
    protected function attachImage(Message $message, string $name = 'photo.png'): Filesystem
    {
        return $this->attachMedia(
            $message,
            $name,
            'png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
        );
    }

    /**
     * An ID3 header and one MPEG frame: enough for finfo to call it `audio/mpeg`, which is all the
     * pipeline keys on.
     */
    protected function attachVoiceNote(Message $message, string $name = 'voice-note.mp3'): Filesystem
    {
        return $this->attachMedia(
            $message,
            $name,
            'mp3',
            "ID3\x03\x00\x00\x00\x00\x00\x00\xff\xfb\x90\x00" . str_repeat("\x00", 400),
        );
    }

    protected function attachMedia(
        Message $message,
        string $name,
        string $fileType,
        string $bytes,
    ): Filesystem {
        // So the assertions below can only be satisfied by the turn this test runs. The caption job is
        // dispatched by every channel responder and runs inline on CI's sync queue, where its own
        // "describe this image" call would overwrite the turn the test is asserting on.
        FakeNeuronProvider::$lastMessages = [];
        Bus::fake([DescribeMessageAttachmentsJob::class]);

        $path = sys_get_temp_dir() . '/' . uniqid('kanvas-inbound-', true) . '.' . $fileType;
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(static fn () => unlink($path));

        $app = app(Apps::class);
        $user = auth()->user();

        $file = new Filesystem();
        $file->apps_id = $app->getId();
        $file->companies_id = $user->getCurrentCompany()->getId();
        $file->users_id = $user->getId();
        $file->name = $name;
        $file->path = 'test/' . $name;
        $file->url = $path;
        $file->size = (string) filesize($path);
        $file->file_type = $fileType;
        $file->save();

        $message->addFile($file, $fileType === 'png' ? 'photo' : 'media');

        return $file;
    }

    /**
     * The user turn the stub provider was last shown: the model saw the picture as an image block
     * and was asked with a non-empty text, so Gemini has something to answer (KANVAS-ECOSYSTEM-6QC).
     */
    protected function assertModelSawAnImageWithText(string $expectedText): void
    {
        $turn = $this->lastUserTurn();
        $images = array_filter($turn->getContentBlocks(), static fn (object $block): bool => $block instanceof ImageContent);

        $this->assertCount(1, $images, 'The attached picture must reach the model as an image block.');
        $this->assertStringContainsString($expectedText, (string) $turn->getContent());
    }

    /**
     * The voice note reached a model that cannot listen as words, never as an audio block.
     */
    protected function assertModelSawATranscriptNotAudio(string $expectedText): void
    {
        $turn = $this->lastUserTurn();
        $audio = array_filter($turn->getContentBlocks(), static fn (object $block): bool => $block instanceof AudioContent);

        $this->assertCount(0, $audio, 'A model that cannot listen must not be handed the audio block.');
        $this->assertStringContainsString($expectedText, (string) $turn->getContent());
    }

    private function lastUserTurn(): UserMessage
    {
        $userTurns = array_values(array_filter(
            FakeNeuronProvider::$lastMessages,
            static fn (object $message): bool => $message instanceof UserMessage,
        ));
        $this->assertNotEmpty($userTurns, 'The provider was never handed a user turn.');

        return end($userTurns);
    }
}
