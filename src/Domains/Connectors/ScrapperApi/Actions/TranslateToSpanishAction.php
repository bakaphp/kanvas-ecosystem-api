<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use GuzzleHttp\Client;

class TranslateToSpanishAction
{
    public static function execute(string $text): string
    {
        $client = new Client();

        $url = 'https://qa-ai-api.vercel.app/api/gemini/translate';

        $body = [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ],
        ];

        $response = $client->post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
        ]);

        return $response->getBody()->getContents();
    }
}
