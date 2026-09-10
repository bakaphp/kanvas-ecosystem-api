<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
use Kanvas\Connectors\Odoo\Services\OdooApiClient;

trait UpsertsByExternalId
{
    protected function upsertByExternalId(
        OdooApiClient $client,
        string $model,
        Model $entity,
        CustomFieldEnum $idField,
        array $data,
    ): array {
        $externalId = $entity->get($idField->value);

        if ($externalId && $client->exists($model, (int) $externalId)) {
            $client->write($model, (int) $externalId, $data);
        } else {
            $externalId = $client->create($model, $data);
            $entity->set($idField->value, (string) $externalId);

            // Odoo's res.partner.create() silently ignores `is_company: true` — confirmed
            // empirically against a real Odoo instance, write() honors it fine. Only worth the
            // extra call for `true`: a fresh partner already defaults to `is_company: false`.
            if (($data['is_company'] ?? false) === true) {
                $client->write($model, $externalId, ['is_company' => true]);
            }
        }

        return $data + ['id' => $externalId];
    }
}
