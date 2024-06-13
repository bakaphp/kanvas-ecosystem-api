<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\ContactType;
use GuzzleHttp\Client;

class ScreeningAction
{
    public function __construct(
        protected People $people,
        protected Apps $app
    ) {
    }

    public function execute(): array
    {
        $client = new Client();
        $email = $this->people->getEmails()->first();
        $linkedin = $this->people->contacts()
            ->where('contacts_types_id',  ContactType::getByName('LinkedIn')->getId())
            ->first();
        $data = [
            'first_name' => $this->people->firstname,
            'last_name' => $this->people->lastname,
            'name' => $this->people->getName(),
            'email' => $email ? $email->value : null,
            'linkedin_url' => $linkedin ? $linkedin->value : null,
        ];
        dump($this->app->get('apollo_api_key'));
        try {
            // Envía la solicitud POST a la API de Apollo.io
            $response = $client->post('https://api.apollo.io/v1/people/match', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-cache',
                    'X-Api-Key' => $this->app->get('apollo-api-key'),
                ],
                'json' => $data,
            ]);

            return json_decode($response->getBody()->getContents(), true)["person"];

        } catch (GuzzleException $e) {
            echo 'Error de Guzzle: ' . $e->getMessage();
        }

    }
}
