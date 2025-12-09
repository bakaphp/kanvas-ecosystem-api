<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\PdfGenerator;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Knp\Snappy\Pdf;

class PdfService
{
    public static function htmlToPdf(
        AppInterface $app,
        UserInterface $user,
        string $html,
        ?string $fileName = null,
        array $options = []
    ): ModelsFilesystem {
        //$response = PdfGenerator::fromHtml($html, $options);
        // Define the file name
        //$fileName = $fileName ?? uniqid('pdf_', true) . '.pdf';
        //$tempFilePath = sys_get_temp_dir() . '/' . $fileName;

        // Define the file name
        $fileName = $fileName ?? uniqid('pdf_', true) . '.pdf';

        // Ensure temp directory exists
        $tempDir = sys_get_temp_dir() ?: '/tmp';
        if (! is_dir($tempDir) || ! is_writable($tempDir)) {
            $tempDir = storage_path('app/temp');
        }

        $tempFilePath = $tempDir . '/' . $fileName;

        $snappy = new Pdf('/usr/bin/wkhtmltopdf', $options);

        $snappy->setOption('encoding', 'UTF-8');
        $snappy->setOption('no-outline', true);
        $snappy->setOption('margin-right', 0);
        $snappy->setOption('margin-left', 0);
        $snappy->setOption('disable-smart-shrinking', true);
        $snappy->setOption('enable-local-file-access', true);
        $snappy->setOption('page-size', 'A4');
        $snappy->setTemporaryFolder($tempDir);
        $snappy->generateFromHtml($html, $tempFilePath);

        // Create an UploadedFile instance from the temporary file
        $uploadedFile = new UploadedFile(
            $tempFilePath,
            $fileName,
            'application/pdf',
            null,
            true
        );

        $filesystem = new FilesystemServices($app);
        $uploadedFileEntry = $filesystem->upload($uploadedFile, $user);

        // Clean up the temporary file
        unlink($tempFilePath);

        // Return the file URL
        return $uploadedFileEntry;
    }

    public static function generatePdfFromTemplate(
        AppInterface $app,
        UserInterface $user,
        string $templateName,
        Model $entity,
        array $data = [],
        array $options = []
    ): ModelsFilesystem {
        $renderTemplate = new RenderTemplateAction($app, $entity->company ?? null);

        $renderTemplateHtml = $renderTemplate->execute(
            $templateName,
            array_merge(['entity' => $entity], $data)
        );

        return self::htmlToPdf(
            app: $app,
            user: $user,
            html: $renderTemplateHtml,
            options: $options
        );
    }
}
