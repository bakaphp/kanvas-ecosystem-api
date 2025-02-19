<?php

declare(strict_types=1);

namespace Baka\Support;

use Baka\Contracts\AppInterface;
use Exception;
use GuzzleHttp\Client;
use Throwable;

class Url
{
    /**
     * Returns a short version of an url using Bit.ly service.
     */
    public static function getShortUrl(string $url, AppInterface $app): string
    {
        //return self::bitlink($url);
        return self::shortLink($url, $app);
    }

    public static function bitlink(string $url, AppInterface $app): string
    {
        $bitlyAccessToken = $app->get('bitly-access-token');

        if (! $bitlyAccessToken) {
            return $url;
        }

        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => getenv('BITLY_HOST'),
            // You can set any number of default request options.
            'timeout' => 2.0,
            // 'headers' => [
            //     'Authorization' => 'Bearer ' . getenv('BITLY_ACCESS_TOKEN')
            // ],
        ]);

        try {
            $response = $client->post('https://api-ssl.bitly.com/v4/shorten', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bitlyAccessToken,
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'long_url' => $url,
                    'domain' => $app->get('custom-short-url') ?? 'bit.ly',
                ],
            ]);
            $response = json_decode((string)$response->getBody(), true);

            return $response['link'];
        } catch (Throwable $ex) {
            //throw $ex;
            //Di::getDefault()->get('log')->error($ex->getMessage());
            return $url;
        }
    }

    public static function shortLink(string $url, AppInterface $app): string
    {
        $shortioAccessToken = $app->get('shortio-access-token');

        $client = new Client([
            // You can set any number of default request options.
            'timeout' => 2.0,
        ]);

        try {
            // Send a POST request to the API
            $response = $client->post('https://api.short.io/links', [
                'headers' => [
                    'Authorization' => $shortioAccessToken,
                    'Accept' => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'originalURL' => $url,
                    'domain' => $app->get('custom-short-url'),
                ],
            ]);

            $response = json_decode((string)$response->getBody(), true);

            if (isset($response['shortURL'])) {
                return (string) $response['shortURL'];
            } else {
                throw new Exception('Error creating short link');
            }
        } catch (Throwable $e) {
            return $url;
        }
    }
}
