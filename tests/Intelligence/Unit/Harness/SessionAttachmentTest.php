<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Connectors\OpenCode\DataTransferObject\SessionAttachment;
use Kanvas\Connectors\OpenCode\Services\SessionAttachmentService;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Tests\TestCase;

final class SessionAttachmentTest extends TestCase
{
    private const string PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testTheExtensionComesFromTheBytesNotTheUploadedName(): void
    {
        $attachment = SessionAttachment::fromFile($this->file(42, 'checkout.heic'), $this->png());

        $this->assertSame('image/png', $attachment->mimeType);
        $this->assertSame('.kanvas/attachments/42-checkout.png', $attachment->relativePath);
    }

    public function testANameCannotReachOutsideTheAttachmentDirectory(): void
    {
        $attachment = SessionAttachment::fromFile($this->file(7, '../../../etc/passwd'), $this->png());

        $this->assertSame('.kanvas/attachments/7-passwd.png', $attachment->relativePath);
    }

    public function testANameWithNothingUsableFallsBackToFile(): void
    {
        $attachment = SessionAttachment::fromFile($this->file(9, '!!!.png'), $this->png());

        $this->assertSame('.kanvas/attachments/9-file.png', $attachment->relativePath);
    }

    public function testNoAttachmentsMeansNoPromptBlock(): void
    {
        $this->assertNull(SessionAttachmentService::promptBlock([]));
    }

    public function testThePromptBlockListsEveryPathAndItsType(): void
    {
        $block = SessionAttachmentService::promptBlock([
            SessionAttachment::fromFile($this->file(1, 'home.png'), $this->png()),
            SessionAttachment::fromFile($this->file(2, 'cart.png'), $this->png()),
        ]);

        $this->assertStringContainsString('- .kanvas/attachments/1-home.png (image/png)', (string) $block);
        $this->assertStringContainsString('- .kanvas/attachments/2-cart.png (image/png)', (string) $block);
        $this->assertStringContainsString('not part of the repository', (string) $block);
    }

    public function testTheAttachmentsBlockSitsRightBeforeTheTask(): void
    {
        $text = new HarnessPrompt(
            task: 'TASK: build it',
            policy: 'POLICY',
            persona: 'PERSONA',
            attachments: 'ATTACHED FILES',
        )->toText();

        $this->assertSame("POLICY\n\n---\n\nPERSONA\n\n---\n\nATTACHED FILES\n\n---\n\nTASK: build it", $text);
    }

    private function file(int $id, string $name): Filesystem
    {
        $file = new Filesystem();
        $file->id = $id;
        $file->name = $name;

        return $file;
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG_1X1, true);
    }
}
