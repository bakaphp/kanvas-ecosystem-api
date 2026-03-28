<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InventorySync\Workflows\Activities;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SyncInventoryActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $syncUrl = $app->get('inventory_sync_url');

        if (empty($syncUrl)) {
            return $this->failWorkflow([
                'status' => 'error',
                'message' => 'Inventory sync URL is not configured',
            ]);
        }

        $client = new Client([
            'base_uri' => $syncUrl,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $response = $client->post('/sync', [
            'json' => [
                'apps_id' => (string) $app->getId(),
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        return [
            'status' => 'success',
            'entity_id' => $entity->getId(),
            'entity_type' => get_class($entity),
            'apps_id' => $app->getId(),
            'sync_response' => $body,
        ];
    }
}
