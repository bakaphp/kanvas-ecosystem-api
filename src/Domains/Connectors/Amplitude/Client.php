<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Amplitude;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use DateTime;
use Illuminate\Support\Facades\Http;
use Kanvas\Exceptions\ValidationException;
use ZipArchive;

class Client
{
    protected string $baseUrl = 'https://amplitude.com/api/2';
    protected string $apiKey;
    protected string $apiSecret;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        if (! $app->get('amplitude_api_key') || ! $app->get('amplitude_api_secret')) {
            throw new ValidationException('Amplitude API key or secret is not set in app settings.');
        }

        $this->apiKey = $app->get('amplitude_api_key');
        $this->apiSecret = $app->get('amplitude_api_secret');
    }

    /**
     * Stream the export data from Amplitude in chunks with a 1-week limit.
     */
    public function eventsExport(string $startDate, string $endDate): array
    {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);

        $interval = $start->diff($end);
        if ($interval->days > 7) {
            throw new ValidationException('The date range cannot exceed 7 days.');
        }

        // Convert to the required Amplitude format Ymd\TH
        $startFormatted = $start->format('Ymd\T00');
        $endFormatted = $end->format('Ymd\T23');  // Assuming you want to include the entire last day

        $path = '/export';
        $params = [
            'start' => $startFormatted,
            'end' => $endFormatted,
        ];

        $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                        ->get($this->baseUrl . $path, $params);

        if ($response->failed()) {
            throw new ValidationException('Failed to fetch data from Amplitude API.');
        }

        // Save the streamed ZIP response to a temporary file
        $tempZipFile = tempnam(sys_get_temp_dir(), 'amplitude_export_');
        file_put_contents($tempZipFile, $response->body());  // Save the entire body to the temp file

        // Extract the ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($tempZipFile) === true) {
            $extractedData = [];

            // Loop through all the files in the ZIP archive
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $fileContents = $zip->getFromIndex($i);

                // If the file is a .json.gz, we need to decompress it
                if (strpos($filename, '.json.gz') !== false) {
                    $decompressed = gzdecode($fileContents);  // Decompress the GZIP file

                    if ($decompressed === false) {
                        throw new ValidationException("Failed to decompress GZIP file: $filename");
                    }

                    // Split decompressed data by newlines (assuming NDJSON format)
                    $lines = explode("\n", $decompressed);

                    // Process each line as a JSON object
                    foreach ($lines as $line) {
                        if (! empty($line)) {
                            $data = json_decode($line, true);
                            if ($data !== null) {
                                $extractedData[] = $data;  // Collect the JSON data
                            }
                        }
                    }
                }
            }
            $zip->close();

            // Clean up the temporary ZIP file
            unlink($tempZipFile);

            return $extractedData;
        } else {
            throw new ValidationException('Failed to open ZIP file.');
        }
    }
}
