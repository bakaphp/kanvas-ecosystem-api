<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Filesystem;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
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
     * With neither app nor platform storage credentials the upload step throws after the PDF is
     * rendered — the exact window where the old code leaked the file.
     */
    public function testHtmlToPdfDeletesTempFileWhenUploadFails(): void
    {
        config(['filesystems.disks.s3.key' => null]);
        $before = $this->tempPdfs();

        try {
            PdfService::htmlToPdf(app: new Apps(), user: Auth::user(), html: '<p>Upload will fail</p>');
            $this->fail('Upload without storage credentials should throw');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('credentials', $e->getMessage());
        }

        $this->assertSame([], array_diff($this->tempPdfs(), $before));
    }

    public function testHtmlToPdfStoresFileUnderGivenCompanyNotUploaderCompany(): void
    {
        $app = app(Apps::class);
        $otherCompany = $this->makeOtherCompany($app);

        $filesystem = PdfService::htmlToPdf(
            app: $app,
            user: Auth::user(),
            html: '<p>Other tenant</p>',
            company: $otherCompany
        );

        try {
            $this->assertSame($otherCompany->getId(), (int) $filesystem->companies_id);
            $this->assertNotSame(Auth::user()->getCurrentCompany()->getId(), (int) $filesystem->companies_id);
        } finally {
            new FilesystemServices($app)->delete($filesystem);
        }
    }

    /**
     * A credit-app PDF generated for a dealership's message by a staff user whose current company is
     * another dealership was saved under the staff user's company, so the message's `files` query
     * (filtered by the message's companies_id) never returned it.
     */
    public function testGeneratePdfFromTemplateStoresFileUnderEntityCompany(): void
    {
        $app = app(Apps::class);
        $otherCompany = $this->makeOtherCompany($app);

        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'pdf-service-entity-company-test',
                'template' => '<p>Message {{ $entity->getId() }}</p>',
            ])
        )->execute();

        $message = Message::factory()->withCompanyId($otherCompany->getId())->create();

        $filesystem = PdfService::generatePdfFromTemplate(
            $app,
            Auth::user(),
            'pdf-service-entity-company-test',
            $message
        );

        try {
            $message->addFile($filesystem, 'test-pdf');

            $this->assertSame($otherCompany->getId(), (int) $filesystem->companies_id);
            $this->assertSame(
                [$filesystem->url],
                $message->getFilesQueryBuilder()->pluck('url')->all()
            );
        } finally {
            new FilesystemServices($app)->delete($filesystem);
        }
    }

    public function testGeneratePdfFromTemplateKeepsCallerFileName(): void
    {
        $app = app(Apps::class);

        new CreateTemplateAction(
            TemplateInput::from([
                'app' => $app,
                'name' => 'pdf-service-file-name-test',
                'template' => '<p>Message {{ $entity->getId() }}</p>',
            ])
        )->execute();

        $message = Message::factory()->create();

        $filesystem = PdfService::generatePdfFromTemplate(
            $app,
            Auth::user(),
            'pdf-service-file-name-test',
            $message,
            fileName: 'named-report-3.pdf'
        );

        try {
            $this->assertSame('named-report-3.pdf', $filesystem->name);
        } finally {
            new FilesystemServices($app)->delete($filesystem);
        }
    }

    private function makeOtherCompany(Apps $app): Companies
    {
        $company = Companies::factory()->create([
            'users_id' => Auth::id(),
            'is_active' => 1,
        ]);
        $company->associateApp($app);

        return $company;
    }

    /**
     * @return list<string>
     */
    private function tempPdfs(): array
    {
        return glob(sys_get_temp_dir() . '/*.pdf') ?: [];
    }
}
