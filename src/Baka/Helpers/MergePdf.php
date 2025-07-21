<?php

declare(strict_types=1);

namespace Baka\Helpers;

use Baka\Contracts\AppInterface;
use CURLFile;
use InvalidArgumentException;

class MergePdf
{
    protected array $files = [];
    protected int $totalFiles = 0;
    protected string $apiKey;

    public function __construct(AppInterface $app, string ...$files)
    {
        $this->files = $files;
        $this->totalFiles = count($files);

        if (empty($app->get('PSPDKIT_KEY'))) {
            throw new InvalidArgumentException('API key is missing');
        }

        $this->apiKey = $app->get('PSPDKIT_KEY');
    }

    /**
     * Generate file instruction.
     */
    protected function generateInstruction(): array
    {
        $fileParts = [];

        for ($i = 0; $i < $this->totalFiles; $i++) {
            $fileParts[] = [
                'file' => 'file_part_' . $i,
            ];
        }

        return $fileParts;
    }

    protected function generateCurlFiles(): array
    {
        $postFiles = [
            'instructions' => json_encode(['parts' => $this->generateInstruction()]),
        ];

        $i = 0;
        foreach ($this->files as $key => $file) {
            $postFiles['file_part_' . $i] = new CURLFile($file);
            $i++;
        }

        return $postFiles;
    }

    public function merge(string $output): bool
    {
        $FileHandle = fopen($output, 'w+');

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.nutrient.io/build',
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_POSTFIELDS => $this->generateCurlFiles(),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_FILE => $FileHandle,
        ]);

        $response = curl_exec($curl);
        curl_close($curl);
        fclose($FileHandle);

        return true;
    }
}
