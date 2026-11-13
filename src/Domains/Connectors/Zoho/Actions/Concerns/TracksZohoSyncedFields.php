<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * A hash of each value last written to the Zoho record, kept on the entity as
 * `['record' => zohoId, 'fields' => [zohoField => md5]]`, so an update sends only what changed in
 * Kanvas. Diffing against Zoho's own copy instead would flag every lookup and date Zoho reformats, and
 * would push stale Kanvas values over edits reps made in Zoho.
 *
 * The hash is of the value we built, never the coerced one Zoho accepted: the next push rebuilds the
 * same uncoerced value and must see it as unchanged. A different record id means the entity was linked
 * to another Zoho record, so nothing it remembers applies there.
 */
trait TracksZohoSyncedFields
{
    use WritesZohoRecordWithCoercion;

    /**
     * @return array the payload Zoho was sent, empty when nothing changed and no call was made
     */
    protected function updateChangedFields(
        ZohoRecordsModule $module,
        Model $entity,
        CustomFieldEnum $field,
        string $zohoRecordId,
        array $data
    ): array {
        $changed = $this->changedFields($entity, $field, $zohoRecordId, $data);

        if ($changed === []) {
            return [];
        }

        $sent = $changed;
        $this->updateRecord($module, $zohoRecordId, $sent);
        $this->rememberSyncedFields($entity, $field, $zohoRecordId, $changed);

        return $sent;
    }

    private function changedFields(
        Model $entity,
        CustomFieldEnum $field,
        string $zohoRecordId,
        array $data
    ): array {
        $synced = $this->syncedFieldHashes($entity, $field, $zohoRecordId);

        return array_filter(
            $data,
            fn (mixed $value, string $key) => ($synced[$key] ?? null) !== $this->hashFieldValue($value),
            ARRAY_FILTER_USE_BOTH
        );
    }

    protected function rememberSyncedFields(
        Model $entity,
        CustomFieldEnum $field,
        ?string $zohoRecordId,
        array $sent
    ): void {
        if (empty($zohoRecordId)) {
            return;
        }

        $synced = $this->syncedFieldHashes($entity, $field, $zohoRecordId);

        foreach ($sent as $key => $value) {
            $synced[$key] = $this->hashFieldValue($value);
        }

        $entity->set($field->value, [
            'record' => $zohoRecordId,
            'fields' => $synced,
        ]);
    }

    private function syncedFieldHashes(Model $entity, CustomFieldEnum $field, string $zohoRecordId): array
    {
        $stored = $entity->get($field->value);

        if (! is_array($stored) || (string) ($stored['record'] ?? '') !== $zohoRecordId) {
            return [];
        }

        return is_array($stored['fields'] ?? null) ? $stored['fields'] : [];
    }

    private function hashFieldValue(mixed $value): string
    {
        return md5((string) json_encode($value));
    }
}
