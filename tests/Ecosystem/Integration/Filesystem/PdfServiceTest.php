<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\PdfService;
use Tests\TestCase;

final class PdfServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }
    }

    public function testHtmlToPdfKeepsCallerFileNameAndDeletesTempFile(): void
    {
        $app = app(Apps::class);
        $before = $this->tempPdfs();

        $filesystem = PdfService::htmlToPdf(
            app: $app,
            user: Auth::user(),
            html: '<h1>Order 123</h1>',
            fileName: 'order-123.pdf'
        );

        try {
            $this->assertSame('order-123.pdf', $filesystem->name);
            $this->assertNotEmpty($filesystem->url);
            $this->assertSame([], array_diff($this->tempPdfs(), $before));
        } finally {
            new FilesystemServices($app)->delete($filesystem);
        }
    }

    public function testHtmlToPdfGeneratesFileNameWhenNoneGiven(): void
    {
        $app = app(Apps::class);

        $filesystem = PdfService::htmlToPdf(app: $app, user: Auth::user(), html: '<p>No name</p>');

        try {
            $this->assertStringStartsWith('pdf_', $filesystem->name);
            $this->assertStringEndsWith('.pdf', $filesystem->name);
        } finally {
            new FilesystemServices($app)->delete($filesystem);
        }
    }

    /**
     * An app with no storage credentials makes the upload step throw after the PDF is rendered —
     * the exact window where the old code leaked the file.
     */
    public function testHtmlToPdfDeletesTempFileWhenUploadFails(): void
    {
        $before = $this->tempPdfs();

        try {
            PdfService::htmlToPdf(app: new Apps(), user: Auth::user(), html: '<p>Upload will fail</p>');
            $this->fail('Upload without storage credentials should throw');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('credentials', $e->getMessage());
        }

        $this->assertSame([], array_diff($this->tempPdfs(), $before));
    }

    /**
     * @return list<string>
     */
    private function tempPdfs(): array
    {
        return glob(sys_get_temp_dir() . '/*.pdf') ?: [];
    }
}
