<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SightEngine\Services;

class ImageContentModerationService
{
    public function scanImage(string $imageUrl): array
    {
        $models = 'nudity-2.1,weapon,offensive-2.0,face-attributes,gore-2.0,violence';
        $params = array(
            'url' =>  $imageUrl,
            'models' => $models,
            'api_user' => env('SIGHTENGINE_API_USER'),
            'api_secret' => env('SIGHTENGINE_API_SECRET'),
        );

        // this example uses cURL
        $ch = curl_init( env('SIGHTENGINE_API_URL') . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        return $this->formatResults(json_decode($response, true));
    }

    private function formatResults(array $results): array
    {
        $nudityStatus = $results['nudity']['sexual_activity'] >= 1 || $results['nudity']['sexual_display'] ? true : false;
        return [
            "scan_status" => $results['successs'],
            "nudity_results" => $nudityStatus
        ];
    }
}
