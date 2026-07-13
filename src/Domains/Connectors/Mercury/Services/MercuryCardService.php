<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

class MercuryCardService extends MercuryApiService
{
    /**
     * Debit + credit cards on one Mercury account. Kept as a flat list on the bank account so card spend
     * can later be attributed to the card (and its holder) that made it.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAccount(string $mercuryAccountId): array
    {
        $response = $this->client->get("account/{$mercuryAccountId}/cards");

        return array_values(array_map(
            fn (array $card): array => [
                'card_id' => (string) ($card['cardId'] ?? ''),
                'last_four' => (string) ($card['lastFourDigits'] ?? ''),
                'name_on_card' => (string) ($card['nameOnCard'] ?? ''),
                'network' => (string) ($card['network'] ?? ''),
                'status' => (string) ($card['status'] ?? ''),
                'type' => (string) ($card['type'] ?? ''),
                'user_id' => (string) ($card['userId'] ?? ''),
            ],
            (array) ($response['cards'] ?? []),
        ));
    }
}
