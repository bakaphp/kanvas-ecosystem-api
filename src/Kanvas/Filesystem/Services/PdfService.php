<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\TempFile;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Kanvas\Filesystem\Models\Filesystem as ModelsFilesystem;
use Kanvas\Templates\Actions\RenderTemplateAction;
use Kanvas\Users\Models\Users;
use Knp\Snappy\Image;
use Knp\Snappy\Pdf;

class PdfService
{
    public const string BINARY_PATH = '/usr/local/bin/wkhtmltopdf';
    public const string IMAGE_BINARY_PATH = '/usr/local/bin/wkhtmltoimage';

    public static function htmlToPdf(
        AppInterface $app,
        UserInterface $user,
        string $html,
        ?string $fileName = null,
        array $options = [],
        ?CompanyInterface $company = null
    ): ModelsFilesystem {
        return self::renderToFilesystem(
            app: $app,
            user: $user,
            company: $company,
            fileName: $fileName ?? uniqid('pdf_', true) . '.pdf',
            extension: 'pdf',
            mimeType: 'application/pdf',
            render: static function (string $tempDir, string $tempFilePath) use ($html, $options): void {
                $snappy = new Pdf(self::BINARY_PATH, $options);

                $snappy->setOption('encoding', 'UTF-8');
                $snappy->setOption('no-outline', true);
                $snappy->setOption('margin-right', 0);
                $snappy->setOption('margin-left', 0);
                $snappy->setOption('disable-smart-shrinking', true);
                $snappy->setOption('enable-local-file-access', true);
                $snappy->setOption('page-size', 'A4');
                $snappy->setTemporaryFolder($tempDir);
                $snappy->generateFromHtml($html, $tempFilePath);
            },
        );
    }

    /**
     * A PNG snapshot of a small HTML fragment (an email signature preview, a card). Unlike htmlToPdf()
     * it never reads local files and runs no JavaScript: the markup may carry a user-chosen image URL.
     */
    public static function htmlToPng(
        AppInterface $app,
        UserInterface $user,
        string $html,
        int $width = 640,
        ?string $fileName = null,
        ?CompanyInterface $company = null
    ): ModelsFilesystem {
        return self::renderToFilesystem(
            app: $app,
            user: $user,
            company: $company,
            fileName: $fileName ?? uniqid('image_', true) . '.png',
            extension: 'png',
            mimeType: 'image/png',
            render: static function (string $tempDir, string $tempFilePath) use ($html, $width): void {
                $snappy = new Image(self::IMAGE_BINARY_PATH);

                $snappy->setOption('encoding', 'UTF-8');
                $snappy->setOption('format', 'png');
                $snappy->setOption('width', $width);
                $snappy->setOption('disable-javascript', true);
                $snappy->setOption('disable-local-file-access', true);
                $snappy->setTemporaryFolder($tempDir);
                $snappy->generateFromHtml($html, $tempFilePath);
            },
        );
    }

    /**
     * @param callable(string, string): void $render receives the temp directory and the path to write
     */
    private static function renderToFilesystem(
        AppInterface $app,
        UserInterface $user,
        ?CompanyInterface $company,
        string $fileName,
        string $extension,
        string $mimeType,
        callable $render
    ): ModelsFilesystem {
        $tempDir = sys_get_temp_dir() ?: '/tmp';
        if (! is_dir($tempDir) || ! is_writable($tempDir)) {
            $tempDir = storage_path('app/temp');
        }
        chdir($tempDir);

        // Never build the temp path from $fileName: callers pass stable names ("order-123.pdf"),
        // so two concurrent renders would share a path and one's cleanup would delete the other's file.
        $tempFilePath = TempFile::path($extension, directory: $tempDir);

        try {
            $render($tempDir, $tempFilePath);

            // The file must belong to the record's tenant. Without an explicit company the upload
            // falls back to the uploader's current company, which is wrong whenever a cross-company
            // user (staff, an agent user) acts on another company's record: the file row is saved
            // under their company and the entity's `files` query filters it out.
            return new FilesystemServices($app, $company)->upload(
                new UploadedFile(
                    path: $tempFilePath,
                    originalName: $fileName,
                    mimeType: $mimeType,
                    test: true
                ),
                $user
            );
        } finally {
            TempFile::delete($tempFilePath);
        }
    }

    public static function generatePdfFromTemplate(
        AppInterface $app,
        UserInterface $user,
        string $templateName,
        Model $entity,
        array $data = [],
        array $options = [],
        ?string $fileName = null
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
            fileName: $fileName,
            options: $options,
            company: $entity->company ?? null
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
            options: $options,
            company: $company
        );
    }
}
