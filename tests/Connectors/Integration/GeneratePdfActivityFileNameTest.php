<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Internal\Activities\GeneratePdfActivity;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\RegistersInternalIntegration;
use Tests\TestCase;

final class GeneratePdfActivityFileNameTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersInternalIntegration;

    protected $connectionsToTransact = [null, 'social', 'ecosystem', 'workflow'];

    private string $templateName;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }

        $this->templateName = 'pdf-file-name-' . uniqid();

        new CreateTemplateAction(
            TemplateInput::from([
                'app' => app(Apps::class),
                'name' => $this->templateName,
                'template' => '<p>Credit app {{ $entity->getId() }}</p>',
            ])
        )->execute();

        $this->registerInternalIntegration();
    }

    public function testMessageFileNameOverridesTheRuleFileName(): void
    {
        $message = Message::factory()->create([
            'message' => [
                'verb' => 'credit-app',
                'data' => ['pdf_file_name' => 'credit-app-4.pdf'],
            ],
        ]);

        $file = $this->runAndFetchFile($message);

        try {
            $this->assertSame('credit-app-4.pdf', $file->name);
            $this->assertSame('credit-app-4.pdf', $file->field_name);
        } finally {
            $this->deleteUpload($file);
        }
    }

    public function testRuleFileNameIsUsedWhenTheMessageCarriesNone(): void
    {
        $message = Message::factory()->create([
            'message' => [
                'verb' => 'credit-app',
                'data' => ['pdf_file_name' => '   '],
            ],
        ]);

        $file = $this->runAndFetchFile($message);

        try {
            $this->assertSame('credit-app-default.pdf', $file->name);
            $this->assertSame('credit-app-default.pdf', $file->field_name);
        } finally {
            $this->deleteUpload($file);
        }
    }

    private function runAndFetchFile(Message $message): Filesystem
    {
        $activity = new GeneratePdfActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );

        $result = $activity->execute($message, app(Apps::class), [
            'template_pdf' => $this->templateName,
            'pdf_file_name' => 'credit-app-default.pdf',
            'sleepTime' => 0,
        ]);

        $this->assertSame('Pdf generated successfully', $result['message'] ?? null, json_encode($result));

        return $message->getFilesQueryBuilder()->sole();
    }

    /**
     * The pivot query selects a partial row with no path, which delete() needs to drop the S3 object.
     */
    private function deleteUpload(Filesystem $file): void
    {
        $app = app(Apps::class);

        new FilesystemServices($app)->delete(Filesystem::getById($file->getId(), $app));
    }
}
