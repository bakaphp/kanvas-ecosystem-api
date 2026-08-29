<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Trello\Activities;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Trello\Enums\ConfigurationEnum;
use Kanvas\Connectors\Trello\Enums\CustomFieldEnum;
use Kanvas\Connectors\Trello\Services\TrelloBoardService;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Creates (or, on a re-run, updates) a Trello card from any Kanvas entity — a new Lead, a support
 * message, a task, whatever the rule is wired to. The entity keeps the created card id in its
 * custom fields (`CustomFieldEnum::TRELLO_CARD_ID`) so retrying the rule edits the same card
 * instead of creating a duplicate every time — the same idempotency shape
 * `PushMessageToWordPressActivity` uses for its WordPress post id.
 */
#[WorkflowAction(
    name: 'Create Trello Card',
    description: 'Creates a Trello card under the given list from a Kanvas entity. If the entity '
        . 'already has a card linked (from a previous run), updates that card instead of creating a '
        . 'duplicate.',
    integration: IntegrationsEnum::TRELLO,
    requiresConfig: [
        ConfigurationEnum::API_KEY,
        ConfigurationEnum::API_TOKEN,
    ],
    requiredParams: ['list_id', 'name'],
    params: [
        'list_id' => 'Trello list id (idList) the card is created under. Required.',
        'name' => 'Card title. Required.',
        'description' => 'Card description (Trello supports Markdown).',
        'due' => 'ISO-8601 due date, e.g. 2026-01-31T12:00:00.000Z.',
    ],
)]
class CreateTrelloCardActivity extends KanvasActivity
{
    public function execute(Model $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $listId = trim((string) ($params['list_id'] ?? ''));
        $name = trim((string) ($params['name'] ?? ''));

        if ($listId === '' || $name === '') {
            return $this->failWorkflow([
                'message' => 'Missing required params "list_id" and/or "name"',
                'entity' => [get_class($entity), $entity->getId()],
            ]);
        }

        $company = $entity->company;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::TRELLO,
            additionalParams: $params,
            integrationOperation: function (Model $entity, Apps $app, mixed $integrationCompany, array $additionalParams) use ($listId, $name, $company): array {
                $service = TrelloBoardService::forApp($app, $company);

                $description = isset($additionalParams['description']) ? (string) $additionalParams['description'] : null;
                $due = isset($additionalParams['due']) ? (string) $additionalParams['due'] : null;

                $existingCardId = $this->existingCardId($entity);

                if ($existingCardId !== null) {
                    $card = $service->updateCard($existingCardId, array_filter([
                        'name' => $name,
                        'desc' => $description,
                        'due' => $due,
                    ]));

                    return ['card' => $card, 'created' => false];
                }

                $card = $service->createCard($listId, $name, $description, array_filter(['due' => $due]));

                if (isset($card['id']) && method_exists($entity, 'set')) {
                    $entity->set(CustomFieldEnum::TRELLO_CARD_ID->value, (string) $card['id']);
                    $entity->set(CustomFieldEnum::TRELLO_LIST_ID->value, $listId);
                }

                return ['card' => $card, 'created' => true];
            },
            company: $company,
        );
    }

    private function existingCardId(Model $entity): ?string
    {
        if (! method_exists($entity, 'get')) {
            return null;
        }

        $cardId = $entity->get(CustomFieldEnum::TRELLO_CARD_ID->value);

        return empty($cardId) ? null : (string) $cardId;
    }
}
