<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Illuminate\Support\Facades\Http;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\PdfIngest\Exceptions\UnsupportedDocumentTypeException;
use Kanvas\Scribe\PdfIngest\Services\GeminiPdfClassifierService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Scribe\ScribeTestCase;

/**
 * The type Gemini is told comes from the bytes, never `file_type` — an email attachment takes that from
 * its URL, and a mislabelled document comes back as a bare 400 INVALID_ARGUMENT (KANVAS-ECOSYSTEM-6CH).
 */
final class ClassifierMimeTypeTest extends ScribeTestCase
{
    public static function readableProvider(): array
    {
        return [
            'pdf' => ["%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj\n<<>>\nendobj\n", 'application/pdf'],
            'phone photo' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00", 'image/jpeg'],
            'screenshot' => ["\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00", 'image/png'],
            'webp' => ["RIFF\x24\x00\x00\x00WEBPVP8 \x18\x00\x00\x00", 'image/webp'],
            'ios photo' => ["\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic", 'image/heic'],
            'heif' => ["\x00\x00\x00\x18ftypmif1\x00\x00\x00\x00mif1heic", 'image/heif'],
        ];
    }

    public static function unreadableProvider(): array
    {
        return [
            'word document' => ["PK\x03\x04\x14\x00\x06\x00\x08\x00\x00\x00!\x00"],
            'gif' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00"],
            'plain text' => ['Thanks for your order, see you soon.'],
        ];
    }

    #[DataProvider('readableProvider')]
    public function test_a_readable_document_is_sent_as_the_type_its_bytes_say(string $bytes, string $expected): void
    {
        $this->assertSame($expected, $this->classifierReturning($bytes)->mimeFor($bytes));
    }

    #[DataProvider('unreadableProvider')]
    public function test_a_type_the_model_cannot_read_is_refused(string $bytes): void
    {
        $this->expectException(UnsupportedDocumentTypeException::class);

        $this->classifierReturning($bytes)->mimeFor($bytes);
    }

    public function test_an_unreadable_file_never_reaches_the_model_whatever_its_file_type_claims(): void
    {
        Http::fake();
        $bytes = "GIF89a\x01\x00\x01\x00\x80\x00\x00";

        try {
            $this->classifierReturning($bytes)->classify($this->createFilesystemRow(extension: 'pdf', fileType: 'pdf'));
            $this->fail('Expected the GIF to be refused.');
        } catch (UnsupportedDocumentTypeException $e) {
            $this->assertSame('image/gif', $e->mimeType);
        }

        Http::assertNothingSent();
    }

    public function test_the_prompt_tells_the_model_a_photo_is_expected(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt();

        $this->assertStringContainsString('photo of a paper receipt', $prompt);
        $this->assertStringNotContainsString('Analyze the attached PDF', $prompt);
    }

    private function classifierReturning(string $bytes): GeminiPdfClassifierService
    {
        return new class ($bytes) extends GeminiPdfClassifierService {
            public function __construct(private readonly string $bytes)
            {
                parent::__construct();
            }

            public function mimeFor(string $bytes): string
            {
                return $this->resolveMimeType($bytes);
            }

            protected function fetchPdfBytes(Filesystem $pdf): string
            {
                return $this->bytes;
            }
        };
    }
}
