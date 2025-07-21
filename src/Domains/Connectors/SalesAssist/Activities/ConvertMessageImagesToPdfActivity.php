<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Knp\Snappy\Pdf;

class ConvertMessageImagesToPdfActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($params) {
                return $this->convertImagesToPdf($message, $app);
            },
            company: $message->company,
        );
    }

    public function convertImagesToPdf(Message $message, AppInterface $app): array
    {
        // Initialize PDF generator with options
        $pdf = new Pdf('/usr/bin/wkhtmltopdf', [
            'encoding' => 'UTF-8',
            'no-outline' => true,
            'margin-right' => 0,
            'margin-left' => 0,
            'disable-smart-shrinking' => true,
            'enable-local-file-access' => true,
            'page-size' => 'A4',
        ]);

        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>PDF with Images</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 20px;
                }
                .container {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                    grid-gap: 20px;
                }
                .image-container {
                    text-align: center;
                }
                .image-container img {
                    max-width: 100%;
                    height: auto;
                    margin-bottom: 10px;
                }
                .caption {
                    font-size: 14px;
                    color: #666;
                }
            </style>
        </head>
        <body>
            <div class="container">
        ';

        $generate = false;
        $messageData = $message->isRoot() ? ($message->message ?? []) : ($message->parent->message ?? []);

        // Process files attached to the message
        $messageFiles = $message->getFiles();
        if ($messageFiles->count() > 0) {
            foreach ($messageFiles as $filesystemEntity) {
                $filesystem = $filesystemEntity->filesystem;

                if ($filesystem->file_type === 'pdf') {
                    continue;
                }

                $generate = true;

                $html .= '
                <div class="image-container">
                    <img src="' . $filesystem->url . '" alt="' . ($filesystem->name ?? '') . '">
                    <p class="caption">' . ($filesystem->name ?? '') . '</p>
                </div>
            ';
            }
        }

        // Handle message data with file sharing
        if (isset($messageData['status']) && $messageData['status'] === 'sent' &&
            ! empty($messageData['data']['file']['url']) &&
            isset($messageData['verb']) && $messageData['verb'] === 'file-sharing') {
            if (! str_contains($messageData['data']['file']['url'], '.pdf')) {
                $generate = true;

                $html .= '
                <div class="image-container">
                    <img src="' . $messageData['data']['file']['url'] . '" alt="' . ($messageData['data']['file']['name'] ?? '') . '">
                    <p class="caption">' . ($messageData['data']['file']['name'] ?? '') . '</p>
                </div>
            ';
            }
        }

        $html .= '
            </div>
        </body>
        </html>';

        if (! $generate) {
            return [
                'result' => false,
                'message' => 'No images found to generate PDF',
                'entity_id' => $message->getId(),
            ];
        }

        // Generate PDF
        $fileName = 'pdf_from_images_' . uniqid() . '.pdf';
        $tempFilePath = sys_get_temp_dir() . '/' . $fileName;

        $pdf->generateFromHtml($html, $tempFilePath);

        // Create UploadedFile instance from temporary file
        $uploadedFile = new UploadedFile(
            $tempFilePath,
            $fileName,
            'application/pdf',
            null,
            true
        );

        // Upload using FilesystemServices
        $filesystemService = new FilesystemServices($app, $message->company ?? null);
        $filesystem = $filesystemService->upload($uploadedFile, $message->user);

        // Update message data if necessary
        if ($generate &&
            isset($messageData['verb']) && $messageData['verb'] === 'file-sharing' &&
            isset($messageData['status']) && $messageData['status'] === 'sent') {
            $messageData['data']['file']['url'] = $filesystem->url;
            $message->message = $messageData;
            $message->saveOrFail();
        }

        // Attach file to message
        $message->addFile($filesystem, 'generated_pdf');

        // If message has parent, also attach to parent
        if (! $message->isRoot()) {
            $message->parent->addFile($filesystem, 'generated_pdf');
        }

        // Clean up temporary file
        unlink($tempFilePath);

        return [
            'result' => true,
            'message' => 'PDF generated successfully from images',
            'entity_id' => $message->getId(),
            'file_id' => $filesystem->getId(),
            'file_url' => $filesystem->url,
            'file_name' => $filesystem->name,
        ];
    }
}
