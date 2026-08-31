<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\PhpOffice\Generators;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Kanvas\Intelligence\Agents\Neuron\Tools\PhpOffice\Contracts\DocumentGeneratorInterface;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use RuntimeException;
use Throwable;

class WordGenerator implements DocumentGeneratorInterface
{
    /**
     * Expected payload:
     * [
     *   'title' => string,
     *   'html_content' => string,
     * ]
     *
     * @param array<string, mixed> $payload
     */
    public function generate(array $payload): string
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $htmlContent = trim((string) ($payload['html_content'] ?? ''));

        if ($title === '' || $htmlContent === '') {
            throw new InvalidArgumentException(
                'WordGenerator requires non-empty "title" and "html_content" values.'
            );
        }

        $phpWord = new PhpWord();
        $phpWord->addTitleStyle(1, ['size' => 20, 'bold' => true, 'color' => '2E2E2E']);
        $phpWord->addTitleStyle(2, ['size' => 16, 'bold' => true, 'color' => '4472C4']);

        $section = $phpWord->addSection([
            'marginTop' => 800,
            'marginBottom' => 800,
        ]);

        $section->addTitle($title, 1);

        try {
            Html::addHtml($section, $htmlContent, false, false);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Could not parse the HTML received to generate the Word document: ' . $e->getMessage(),
                previous: $e
            );
        }

        $filename = Str::slug($title) . '-' . time() . '.docx';
        $path = storage_path("app/generated/{$filename}");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }
}
