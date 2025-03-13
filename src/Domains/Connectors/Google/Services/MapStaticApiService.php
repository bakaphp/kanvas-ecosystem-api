<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use GuzzleHttp\Client;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Services\FilesystemServices;
use Illuminate\Http\UploadedFile;
use finfo;
use Kanvas\Users\Models\Users;

class MapStaticApiService
{
    public const DEFAULT_ZOOM = '20';
    public const DEFAULT_SIZE = '600x400';
    /**
     * Get the top 3 most relevant tags for a given message.
     */
    public static function getImageFromCoordinates(float $latitude, float $longitude, ?string $zoom = null): string
    {
        $apiKey = env('MAP_STATIC_API_KEY');
        $zoom = $zoom ?? self::DEFAULT_ZOOM;
        $size = self::DEFAULT_SIZE;
        $marker = "$latitude,$longitude";
        $url = "https://maps.googleapis.com/maps/api/staticmap?center=$latitude,$longitude&zoom=$zoom&size=$size&markers=color:red|$marker&key=$apiKey";
        $client = new Client();

        $response = $client->get($url);
        $body = $response->getBody();
        $tempFilePath = sys_get_temp_dir() . '/' . uniqid() . '.png';
        file_put_contents($tempFilePath, $body);

        return $tempFilePath;
    }
}
