<?php

declare(strict_types=1);

namespace Baka\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client;
use Kanvas\Connectors\ShortIo\Services\ShortIoConfigurationService;
use Throwable;

class Url
{
    /**
     * Shortens through the company's short.io integration, else the app's.
     */
    public static function getShortUrl(string $url, AppInterface $app, ?CompanyInterface $company = null): string
    {
        return self::shortLink($url, $app, $company);
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

    /**
     * A send never fails on shortening: any miss returns the long URL.
     */
    public static function shortLink(string $url, AppInterface $app, ?CompanyInterface $company = null): string
    {
        if (trim($url) === '') {
            return $url;
        }

        $client = ShortIoConfigurationService::client($app, $company);
        if ($client === null) {
            return $url;
        }

        try {
            return $client->createLink($url);
        } catch (Throwable) {
            return $url;
        }
    }
}
