<?php

declare(strict_types=1);

namespace Baka\Support;

class PdfGenerator
{
    protected static string $apiUrl;
    protected static array $options = [
        'format' => 'A4', // PDF format
        'printBackground' => true, // Include background colors/images
    ];

    protected static function init(): void
    {
        self::$apiUrl = config('kanvas.puppeteer.url', 'http://puppeteer:3000/pdf');
    }

    public static function fromUrl(string $url, array $options = []): ?string
    {
        self::init();

        $data = [
            'url' => $url,
            'options' => array_merge(self::$options, $options),
        ];

        return self::generatePdf($data);
    }

    public static function fromHtml(string $html, array $options = []): ?string
    {
        self::init();

        $data = [
            'html' => $html,
            'options' => array_merge(self::$options, $options),
        ];

        return self::generatePdf($data);
    }

    /**
     * Generate a PDF using the Puppeteer API.
     *
     * @param array $data The payload to send to Puppeteer.
     * @param string|null $fileName Optional file name for the generated PDF.
     * @return string|null The storage path of the generated PDF or null on failure.
     */
    protected static function generatePdf(array $data): ?string
    {
        $ch = curl_init(self::$apiUrl);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);

        if ($response === false) {
            logger()->error('Error generating PDF: ' . curl_error($ch));
            curl_close($ch);

            return null;
        }

        curl_close($ch);

        return $response;
    }
}
