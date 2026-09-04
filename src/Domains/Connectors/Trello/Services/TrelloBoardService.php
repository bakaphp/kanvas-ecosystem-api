<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Trello\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Trello\Client;

/**
 * High-level Trello operations built on top of the raw `Client` — boards/lists lookups and card
 * create/update, which is all a workflow rule or a "sync a task to Trello" action needs.
 */
class TrelloBoardService
{
    public function __construct(protected Client $client)
    {
    }

    public static function forApp(AppInterface $app, CompanyInterface $company): self
    {
        return new self(new Client($app, $company));
    }

    /**
     * Boards the token's member belongs to.
     *
     * @return array<int, array<string, mixed>>
     */
    public function boards(): array
    {
        return $this->client->get('members/me/boards', ['fields' => 'name,id,url,closed']);
    }

    /**
     * @return array<string, mixed>
     */
    public function board(string $boardId): array
    {
        return $this->client->get('boards/' . $boardId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lists(string $boardId, bool $includeClosed = false): array
    {
        return $this->client->get('boards/' . $boardId . '/lists', [
            'filter' => $includeClosed ? 'all' : 'open',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function card(string $cardId): array
    {
        return $this->client->get('cards/' . $cardId);
    }

    /**
     * @param array<string, mixed> $options Extra Trello card fields: due, idMembers, idLabels, pos...
     * @return array<string, mixed>
     */
    public function createCard(
        string $listId,
        string $name,
        ?string $description = null,
        array $options = []
    ): array {
        return $this->client->post('cards', array_merge($options, array_filter([
            'idList' => $listId,
            'name' => $name,
            'desc' => $description,
        ])));
    }

    /**
     * @param array<string, mixed> $fields Any Trello card field: name, desc, due, idList, closed...
     * @return array<string, mixed>
     */
    public function updateCard(string $cardId, array $fields): array
    {
        return $this->client->put('cards/' . $cardId, $fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function moveCardToList(string $cardId, string $listId): array
    {
        return $this->updateCard($cardId, ['idList' => $listId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function archiveCard(string $cardId): array
    {
        return $this->updateCard($cardId, ['closed' => 'true']);
    }

    /**
     * @return array<string, mixed>
     */
    public function addComment(string $cardId, string $text): array
    {
        return $this->client->post('cards/' . $cardId . '/actions/comments', ['text' => $text]);
    }
}
