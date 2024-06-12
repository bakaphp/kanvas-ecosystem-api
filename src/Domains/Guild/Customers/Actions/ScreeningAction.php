<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Apps\Models\App;
use Kanvas\Guild\Customers\Models\People;

class ScreeningAction
{
    public function __construct(
        protected People $people,
        protected App $app
    ) {
    }

    public function execute(): array
    {
        $email = $this->people->getEmails();
        $data = [
            'first_name' => $this->people->firstname,
            'last_name' => $this->people->lastname,
            'name' => $this->people->getName(),
            'email' => $email ? $email->value : null,
            'reveal_personal_email' => true,
            'reveal_phone_number' => true,
        ];
        try {
            // Envía la solicitud POST a la API de Apollo.io
            $response = $client->post('https://api.apollo.io/v1/people/match', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-cache',
                    'X-Api-Key' => $apiKey,
                ],
                'json' => $data,
            ]);

            return json_decode($response->getBody()->getContents(), true)["person"];

        } catch (GuzzleException $e) {
            echo 'Error de Guzzle: ' . $e->getMessage();
        }

    }
}
