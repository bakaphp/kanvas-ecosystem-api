<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Client as ApolloClient;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;

class ScreeningAction
{
    public function __construct(
        protected People $people,
        protected Apps $app
    ) {
    }

    public function execute(): array
    {
        $client = new ApolloClient($this->app);
        $email = $this->people->getEmails()->first();
        $linkedin = $this->people->contacts()
            ->where('contacts_types_id', ContactType::getByName('LinkedIn')->getId())
            ->first();

        $data = [
            'first_name' => $this->people->firstname,
            'last_name' => $this->people->lastname,
            'name' => $this->people->getName(),
            'email' => $email?->value,
            'linkedin_url' => $linkedin?->value,
        ];

        $response = $client->post('/people/match', $data);

        return $response['person'] ?? [];
    }
}
