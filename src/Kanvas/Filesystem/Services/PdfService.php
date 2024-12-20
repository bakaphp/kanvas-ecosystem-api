<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Awobaz\Compoships\Database\Eloquent\Model;
use Baka\Contracts\AppInterface;
use Baka\Support\PdfGenerator;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Http\UploadedFile;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Templates\Actions\RenderTemplateAction;

class PdfService
{
    public static function htmlToPdf(
        AppInterface $app,
        UserInterface $user,
        string $html,
        ?string $fileName = null,
        array $options = []
    ): ModelsFilesystem {
        $response = PdfGenerator::fromHtml($html, $options);

        // Define the file name
        $fileName = $fileName ?? uniqid('pdf_', true) . '.pdf';
        $tempFilePath = sys_get_temp_dir() . '/' . $fileName;

        // Save the content temporarily
        if (file_put_contents($tempFilePath, $response) === false) {
            logger()->error('Failed to save PDF to temporary location.');

            return null;
        }

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
        Model $entity
    ): ModelsFilesystem {
        $renderTemplate = new RenderTemplateAction($app);

        $renderTemplateHtml = $renderTemplate->execute(
            $templateName,
            ['entity' => $entity]
        );

        return self::htmlToPdf(
            $app,
            $user,
            $renderTemplateHtml
        );
    }
}
