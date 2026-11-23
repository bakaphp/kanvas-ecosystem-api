<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Kanvas\Communication\Messages\Actions\StoreInboundFileAction;
use Kanvas\Social\Messages\Models\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StoreInboundFileActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'social'];

    public static function audioTypes(): array
    {
        return [
            'whatsapp voice note' => ['audio/ogg; codecs=opus', 'ogg'],
            'opus file' => ['audio/opus', 'ogg'],
            'mp3' => ['audio/mpeg', 'mp3'],
            'm4a' => ['audio/x-m4a', 'm4a'],
            'wav' => ['audio/wav', 'wav'],
        ];
    }

    #[DataProvider('audioTypes')]
    public function testAudioIsStoredUnderTheExtensionTheModelReads(string $contentType, string $extension): void
    {
        $file = new StoreInboundFileAction(
            Message::factory()->create(),
            'fake-audio-bytes',
            $contentType,
            'test'
        )->execute();

        $this->assertSame($extension, $file->file_type);
        $this->assertStringEndsWith('.' . $extension, $file->name);
    }

    public function testAnUnknownTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StoreInboundFileAction(
            Message::factory()->create(),
            'bytes',
            'application/x-msdownload',
            'test'
        )->execute();
    }
}
