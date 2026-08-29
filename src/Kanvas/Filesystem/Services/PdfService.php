<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\PdfGenerator;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Users\Models\Users;
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
        chdir($tempDir);

        $tempFilePath = $tempDir . '/' . $fileName;

        $snappy = new Pdf('/usr/local/bin/wkhtmltopdf', $options);

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

        // wkhtmltopdf can't decode HEIC/HEIF/TIFF/etc — convert any such <img> sources first.
        $renderTemplateHtml = ImageConversionService::convertHtmlImagesToViewable(
            $renderTemplateHtml,
            $app,
            user: $entity->user ?? null,
            company: $entity->company ?? null,
        );

        return self::htmlToPdf(
            app: $app,
            user: $user,
            html: $renderTemplateHtml,
            options: $options
        );
    }

    /**
     * One image per page, in the order given.
     *
     * @param array<int, array{url: string, caption?: string}> $images
     */
    public static function imagesToPdf(
        AppInterface $app,
        Users $user,
        array $images,
        ?CompanyInterface $company = null,
        ?string $fileName = null,
        array $options = []
    ): ModelsFilesystem {
        $pages = '';

        foreach ($images as $image) {
            $url = (string) ($image['url'] ?? '');

            if ($url === '') {
                continue;
            }

            $caption = (string) ($image['caption'] ?? '');
            $pages .= '<div class="page">'
                . '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . htmlspecialchars($caption, ENT_QUOTES) . '">'
                . ($caption !== '' ? '<p class="caption">' . htmlspecialchars($caption, ENT_QUOTES) . '</p>' : '')
                . '</div>';
        }

        if ($pages === '') {
            throw new InvalidArgumentException('No images given to render into a PDF');
        }

        // page-break-before on every sibling but the first: page-break-after on the
        // last element makes wkhtmltopdf emit a trailing blank page.
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                @page { margin: 0; }
                body { margin: 0; font-family: Arial, sans-serif; }
                .page { box-sizing: border-box; padding: 20px; text-align: center; }
                .page + .page { page-break-before: always; }
                .page img { max-width: 100%; max-height: 950px; height: auto; }
                .caption { font-size: 14px; color: #666; margin-top: 10px; }
            </style>
        </head>
        <body>' . $pages . '</body>
        </html>';

        // wkhtmltopdf cannot decode HEIC/HEIF/TIFF/etc — convert any such source first.
        $html = ImageConversionService::convertHtmlImagesToViewable(
            $html,
            $app,
            user: $user,
            company: $company,
        );

        return self::htmlToPdf(
            app: $app,
            user: $user,
            html: $html,
            fileName: $fileName,
            options: $options
        );
    }
}
