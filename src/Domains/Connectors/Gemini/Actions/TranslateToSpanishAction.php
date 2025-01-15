<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Gemini\Actions;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;

use function Sentry\captureException;

class TranslateToSpanishAction
{
    public static function execute(string $text): ?string
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

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($body),
            ]);

            $response = json_decode($response->getBody()->getContents(), true);

            return $response['producto_es'];
        } catch (ServerException $e) {
            captureException($e);

            return null;
        } catch (Exception $e) {
            captureException($e);

            return null;
        }
    }
}
