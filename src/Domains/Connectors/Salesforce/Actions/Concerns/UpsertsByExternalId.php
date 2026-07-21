<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;

trait UpsertsByExternalId
{
    protected function upsertByExternalId(
        SalesforceApiClient $client,
        string $sobject,
        Model $entity,
        CustomFieldEnum $idField,
        array $data,
    ): array {
        $externalId = $entity->get($idField->value);

        if (! $externalId || $client->find($sobject, (string) $externalId) === null) {
            $externalId = $client->create($sobject, $data);
            $entity->set($idField->value, $externalId);
        } else {
            $client->update($sobject, (string) $externalId, $data);
        }

        return $data + ['id' => $externalId];
    }
}
