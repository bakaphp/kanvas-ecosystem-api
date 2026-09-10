<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Kanvas\Scribe\PdfIngest\Services\GeminiPdfClassifierService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Scribe\ScribeTestCase;

/**
 * A receipt photographed on a phone is the ordinary case for an employee expense. Labelling those
 * bytes `application/pdf` is what made Gemini refuse a file it can read perfectly well.
 */
final class ClassifierMimeTypeTest extends ScribeTestCase
{
    public static function imageTypeProvider(): array
    {
        return [
            'phone photo' => ['jpg', 'image/jpeg'],
            'jpeg long form' => ['jpeg', 'image/jpeg'],
            'screenshot' => ['png', 'image/png'],
            'ios photo' => ['heic', 'image/heic'],
            'heif' => ['heif', 'image/heif'],
            'webp' => ['webp', 'image/webp'],
        ];
    }

    #[DataProvider('imageTypeProvider')]
    public function test_an_image_receipt_is_sent_as_its_own_image_type(string $extension, string $expected): void
    {
        $file = $this->createFilesystemRow(extension: $extension, fileType: $extension);

        $this->assertSame($expected, $this->resolveMimeFor($file));
    }

    public function test_a_pdf_is_unchanged(): void
    {
        $file = $this->createFilesystemRow(extension: 'pdf', fileType: 'pdf');

        $this->assertSame('application/pdf', $this->resolveMimeFor($file));
    }

    /**
     * The accounting inbox is PDF in practice, so an unknown type keeps the historical label rather
     * than failing the ingest outright — a wrong guess there is no worse than before this existed.
     */
    public function test_an_unrecognised_type_falls_back_to_pdf(): void
    {
        $file = $this->createFilesystemRow(extension: 'xyz', fileType: 'xyz');

        $this->assertSame('application/pdf', $this->resolveMimeFor($file));
    }

    public function test_the_prompt_tells_the_model_a_photo_is_expected(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt();

        $this->assertStringContainsString('photo of a paper receipt', $prompt);
        $this->assertStringNotContainsString('Analyze the attached PDF', $prompt);
    }

    private function resolveMimeFor(object $file): string
    {
        $service = new class () extends GeminiPdfClassifierService {
            public function mimeFor(object $file): string
            {
                return $this->resolveMimeType($file);
            }
        };

        return $service->mimeFor($file);
    }
}
