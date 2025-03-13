<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Google\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use Throwable;

class MapStaticApiService
{
    public const DEFAULT_ZOOM = '20';
    public const DEFAULT_SIZE = '600x400';
    /**
     * Get the top 3 most relevant tags for a given message.
     */
    public static function getImageFromCoordinates(float $latitude, float $longitude, ?string $zoom = null): string
    {
        $apiKey = config('services.google.maps.api_key');
        $zoom = $zoom ?? self::DEFAULT_ZOOM;
        $size = self::DEFAULT_SIZE;
        $marker = "$latitude,$longitude";

        try {
            $url = "https://maps.googleapis.com/maps/api/staticmap?center=$latitude,$longitude&zoom=$zoom&size=$size&markers=color:red|$marker&key=$apiKey";
            $client = new Client();

            $response = $client->get($url);
            $body = $response->getBody();
            $tempFilePath = sys_get_temp_dir() . '/' . uniqid() . '.png';
            file_put_contents($tempFilePath, $body);
        } catch (RequestException $e) {
            // Handle HTTP request errors (like 404, 500, etc.)
            Log::error("RequestException: " . $e->getMessage());
        } catch (ConnectException $e) {
            // Handle connection errors (like network issues)
            Log::error("ConnectException: " . $e->getMessage());
        } catch (Throwable $th) {
            // Fallback for any other PHP error or throwable
            Log::error("Throwable Error: " . $th->getMessage());
        }


        return $tempFilePath;
    }
}
