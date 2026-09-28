<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Throwable;

/**
 * Read-only probe of where a given Intras install keeps its participant attributes.
 *
 * SIPGO spreads them across two places — plain columns on `participants` and
 * per-participant rows in `participants_custom_fields` — and the custom-field
 * names were typed in per agency, so they differ between installs. Run this
 * before adding anything to ParticipantMapper::PROFILE_FIELD_MAP.
 */
class InspectParticipantFieldsFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
    ) {
    }

    /**
     * @return array{columns: array<int, array{name: string, type: string}>, custom_fields: array<int, array{name: string, filled: int}>, lookups: array<string, array{rows: array<int, array{id: int, name: string}>, error: string|null}>}
     */
    public function execute(): array
    {
        $client = new Client($this->app);

        $columns = [];
        foreach ($client->getConnection()->select('SHOW COLUMNS FROM `participants`') as $column) {
            $columns[] = [
                'name' => (string) $column->Field,
                'type' => (string) $column->Type,
            ];
        }

        $customFields = $client->table('participants_custom_fields as pcf')
            ->join('custom_fields as cf', 'cf.id', '=', 'pcf.custom_fields_id')
            ->whereNotNull('pcf.value')
            ->where('pcf.value', '!=', '')
            ->groupBy('cf.name')
            ->orderByDesc('filled')
            ->selectRaw('cf.name as name, COUNT(*) as filled')
            ->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'filled' => (int) $row->filled])
            ->all();

        return [
            'columns' => $columns,
            'custom_fields' => $customFields,
            'lookups' => $this->lookupCatalogs($client),
        ];
    }

    /**
     * Dump the catalogs behind the participant profile FKs, so a wrong table name
     * (or a table that turns out not to be a catalog at all) is visible at a glance.
     *
     * @return array<string, array{rows: array<int, array{id: int, name: string}>, error: string|null}>
     */
    protected function lookupCatalogs(Client $client): array
    {
        $catalogs = [];

        foreach (ParticipantMapper::lookupTables() as $table) {
            try {
                $rows = $client->table($table)
                    ->orderBy('id')
                    ->limit(50)
                    ->get()
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => trim((string) ($row->name ?? ''))])
                    ->all();

                $catalogs[$table] = ['rows' => $rows, 'error' => null];
            } catch (Throwable $e) {
                $catalogs[$table] = ['rows' => [], 'error' => $e->getMessage()];
            }
        }

        return $catalogs;
    }
}
