<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Carbon\Carbon;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\SipgoEntityEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Writes one approved change set to a SIPGO row and its custom fields in a single transaction. Only
 * `to` values are written: `from` and `label` exist for the approver, not for SIPGO. Takes the caller's
 * Client: constructing a new one purges the `intras` connection, which would end a transaction the
 * caller has open around several items.
 */
class WriteSipgoChangeSetAction
{
    /**
     * @param array{row?: array<string, array{to: string}>, custom_fields?: array<string, array{to: string}>} $changes
     */
    public function __construct(
        protected Client $client,
        protected SipgoEntityEnum $kind,
        protected ?int $legacyId,
        protected array $changes,
    ) {
    }

    /**
     * A null legacy id inserts the row and reports the id SIPGO assigned.
     *
     * @return array{kind: string, legacy_id: int, row: list<string>, custom_fields: list<string>}
     */
    public function execute(): array
    {
        $columns = array_map(fn (array $change) => $change['to'], $this->changes['row'] ?? []);
        $customFields = array_map(fn (array $change) => $change['to'], $this->changes['custom_fields'] ?? []);

        if ($this->legacyId !== null && $columns === [] && $customFields === []) {
            return $this->result($this->legacyId, $columns, $customFields);
        }

        $legacyId = $this->client->getConnection()->transaction(function () use ($columns, $customFields): int {
            $now = Carbon::now()->toDateTimeString();

            if ($this->legacyId === null) {
                $legacyId = (int) $this->client->table($this->kind->table())->insertGetId([
                    ...$columns,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $legacyId = $this->legacyId;
                $row = $this->client->table($this->kind->table())->where('id', $legacyId);

                if (! (clone $row)->exists()) {
                    throw new ValidationException(sprintf('SIPGO %s %d no longer exists.', $this->kind->value, $legacyId));
                }

                $row->update([...$columns, 'updated_at' => $now]);
            }

            $this->writeCustomFields($legacyId, $customFields);

            return $legacyId;
        });

        return $this->result($legacyId, $columns, $customFields);
    }

    private function result(int $legacyId, array $columns, array $customFields): array
    {
        return [
            'kind' => $this->kind->value,
            'legacy_id' => $legacyId,
            'row' => array_keys($columns),
            'custom_fields' => array_keys($customFields),
        ];
    }

    /**
     * The `*_custom_fields` tables have no unique key and SIPGO already carries duplicate rows per
     * (entity, field), so every matching row is updated and a row is inserted only when none exists.
     *
     * @param array<string, string> $values
     */
    protected function writeCustomFields(int $legacyId, array $values): void
    {
        if ($values === []) {
            return;
        }

        $fieldIds = $this->client->table('custom_fields')
            ->where('modules_id', $this->kind->customFieldsModuleId())
            ->whereIn('name', array_keys($values))
            ->pluck('id', 'name');

        $missing = array_diff(array_keys($values), $fieldIds->keys()->all());

        if ($missing !== []) {
            throw new ValidationException(sprintf(
                'SIPGO has no %s custom field named: %s',
                $this->kind->value,
                implode(', ', $missing),
            ));
        }

        foreach ($values as $name => $value) {
            $rows = $this->client->table($this->kind->customFieldsTable())
                ->where($this->kind->foreignKey(), $legacyId)
                ->where('custom_fields_id', $fieldIds[$name]);

            if ((clone $rows)->exists()) {
                $rows->update(['value' => $value]);

                continue;
            }

            $this->client->table($this->kind->customFieldsTable())->insert([
                $this->kind->foreignKey() => $legacyId,
                'custom_fields_id' => $fieldIds[$name],
                'value' => $value,
            ]);
        }
    }
}
