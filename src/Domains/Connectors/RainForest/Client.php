<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RainForest;

use GuzzleHttp\Client as GuzzleClient;

class Client
{
    public static function getClient()
    {
        return new GuzzleClient([
             'base_uri' => 'https://api.rainforestapi.com',
             'timeout' => 180,
             'headers' => [
                 'Accept' => 'application/json',
                 'Content-Type' => 'application/json',
             ],
         ]);
    }
}
