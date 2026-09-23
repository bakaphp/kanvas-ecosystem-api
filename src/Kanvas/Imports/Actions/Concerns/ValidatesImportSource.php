<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\DataTransferObject\ImportSourceData;
use Kanvas\Imports\Validations\ImportSchedule;

/**
 * Every referenced record must belong to the source's company (or be an app-wide row where that is
 * allowed): the ids come from a form, and a scheduled job later acts on them with no user present.
 */
trait ValidatesImportSource
{
    protected function validatedAttributes(ImportSourceData $data): array
    {
        if ($data->name === '') {
            throw new ValidationException('A scheduled import needs a name.');
        }

        $this->assertOwned($data, 'Mapper', $data->mapper);
        $this->assertOwned(
            data: $data,
            label: 'Region',
            record: $data->region,
            allowAppWide: true
        );
        $this->assertOwned(
            data: $data,
            label: 'Connection',
            record: $data->connection,
            allowAppWide: true
        );

        if ($data->warehouse !== null) {
            $this->assertOwned($data, 'Warehouse', $data->warehouse);
        }

        if ($data->channel !== null) {
            $this->assertOwned(
                data: $data,
                label: 'Channel',
                record: $data->channel,
                allowAppWide: true
            );
        }

        if ($data->unpublishMissing && $data->channel === null) {
            throw new ValidationException('Unpublishing cars that left the feed needs a channel.');
        }

        ImportSchedule::assertValid($data->schedule, $data->timezone);

        return [
            'users_id' => $data->user->getId(),
            'regions_id' => $data->region->getId(),
            'filesystem_mapper_id' => $data->mapper->getId(),
            'import_connections_id' => $data->connection->getId(),
            'warehouses_id' => $data->warehouse?->getId(),
            'channels_id' => $data->channel?->getId(),
            'name' => $data->name,
            'root' => $data->root,
            'files' => $this->normalizeFiles($data->files),
            'unpublish_missing' => $data->unpublishMissing,
            'extra' => $data->extra,
            'schedule' => $data->schedule,
            'timezone' => $data->timezone,
            'is_active' => $data->isActive,
        ];
    }

    private function assertOwned(
        ImportSourceData $data,
        string $label,
        Model $record,
        bool $allowAppWide = false
    ): void {
        $companiesId = (int) $record->getAttribute('companies_id');
        $ownCompany = $companiesId === $data->branch->company->getId() || ($allowAppWide && $companiesId === 0);

        if ((int) $record->getAttribute('apps_id') !== $data->app->getId() || ! $ownCompany) {
            throw new ValidationException($label . ' does not belong to this company.');
        }
    }

    /**
     * @return list<array{pattern: string, filter: array{column: string, in: list<string>}|null, required: bool}>
     */
    private function normalizeFiles(array $files): array
    {
        if ($files === []) {
            throw new ValidationException('A scheduled import needs at least one file.');
        }

        $normalized = [];
        foreach ($files as $file) {
            $pattern = trim((string) ($file['pattern'] ?? ''));
            $filter = $file['filter'] ?? null;

            if ($pattern === '') {
                throw new ValidationException('Every file needs a name or pattern.');
            }

            if ($filter !== null && (trim((string) ($filter['column'] ?? '')) === '' || empty($filter['in']))) {
                throw new ValidationException('The filter on ' . $pattern . ' needs a column and at least one value.');
            }

            $normalized[] = [
                'pattern' => $pattern,
                'filter' => $filter === null ? null : ['column' => trim((string) $filter['column']), 'in' => array_values((array) $filter['in'])],
                'required' => (bool) ($file['required'] ?? true),
            ];
        }

        return $normalized;
    }
}
