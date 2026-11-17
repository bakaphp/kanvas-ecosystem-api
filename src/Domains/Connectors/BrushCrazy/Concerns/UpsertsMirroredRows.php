<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Concerns;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;

/**
 * The one write path every BrushCrazy sync uses for its rows. The host class provides `$app`
 * (AppInterface), `$context` (StudioContext) and `$idMaps` (IdMapRepository).
 */
trait UpsertsMirroredRows
{
    /** @var array<string, array<string, int>> "{model}|{field}" => external id => kanvas id */
    protected array $mirroredIdMaps = [];

    /** @var array<string, array<int, array<string, string|null>>> model => kanvas id => field => stored value */
    protected array $mirroredFieldValues = [];

    /** @var array{key: string, values: array<string, string|null>}|null the last row read outside a preload */
    protected ?array $lastMirroredFields = null;

    /**
     * Narrows the id map to one chunk instead of loading every row of the company (~96k People for
     * the biggest studio).
     *
     * @param  class-string<Model>  $model
     * @param  array<int, int|string>  $externalIds
     */
    protected function preloadMirroredIds(string $model, CustomFieldEnum $field, array $externalIds): void
    {
        $this->mirroredIdMaps[$model . '|' . $field->value] = $this->idMaps->loadFor(
            $this->context->companyId(),
            $model,
            $field,
            $externalIds,
        );
    }

    /**
     * A preloaded miss is still looked up: another writer (the webhook, a registration creating its
     * buyer) may have created the row since the chunk was preloaded.
     *
     * @param  class-string<Model>  $model
     */
    protected function mirroredId(string $model, CustomFieldEnum $field, int|string $externalId): ?int
    {
        $key = (string) $externalId;
        $map = $this->mirroredIdMaps[$model . '|' . $field->value] ?? [];

        if (array_key_exists($key, $map)) {
            return $map[$key];
        }

        $id = $this->idMaps->loadFor(
            $this->context->companyId(),
            $model,
            $field,
            [$externalId],
        )[$key] ?? null;

        if ($id !== null) {
            $this->rememberMirroredId(
                $model,
                $field,
                $externalId,
                $id
            );
        }

        return $id;
    }

    /**
     * Resolves the row by its external id and upserts it; see upsertMirrored(). The external-id
     * custom field itself is written by the caller, so a new row is remembered here for the rest of
     * the chunk.
     *
     * @template T of Model
     * @param  class-string<T>  $model
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $insertOnly
     * @return T
     */
    protected function upsertMirroredById(
        string $model,
        CustomFieldEnum $field,
        int|string $externalId,
        array $attributes,
        array $insertOnly = []
    ): Model {
        $id = $this->mirroredId($model, $field, $externalId);

        $row = $this->upsertMirrored(
            $model,
            $id !== null ? ['id' => $id] : null,
            $attributes,
            $insertOnly
        );

        $this->rememberMirroredId(
            $model,
            $field,
            $externalId,
            (int) $row->getKey()
        );

        return $row;
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function rememberMirroredId(
        string $model,
        CustomFieldEnum $field,
        int|string $externalId,
        int $id
    ): void {
        $this->mirroredIdMaps[$model . '|' . $field->value][(string) $externalId] = $id;
    }

    /**
     * Model events stay off for the whole write: the observers on these models queue duplicate
     * checks, create social channels and re-aggregate parents per row, which turns a bulk import
     * into hours of work. UuidTrait and SlugTrait do not fire either, so the caller passes uuid/slug
     * in `$insertOnly`, together with anything else written on insert only (a source created_at).
     *
     * withTrashed(): a row deleted at the source is written flagged, and the global
     * `is_deleted = 0` scope would hide it from the next run, which would then create a duplicate.
     *
     * Explicit apps_id/companies_id rather than fromApp()/fromCompany(): under an AppKey request
     * fromCompany() widens to every company.
     *
     * Workflows are switched off on the returned instance: HasCustomFields::set() fires
     * CREATE_CUSTOM_FIELD and model events being off does not stop it, so every custom field the
     * caller writes next would run the app's rules over historical data.
     *
     * @template T of Model
     * @param  class-string<T>  $model
     * @param  array<string, mixed>|null  $match  null when the row is known not to exist
     * @param  array<string, mixed>  $attributes  `is_deleted`, when present, goes through mirroredDeletedState()
     * @param  array<string, mixed>  $insertOnly
     * @return T
     */
    protected function upsertMirrored(
        string $model,
        ?array $match,
        array $attributes,
        array $insertOnly = []
    ): Model {
        $tenant = [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->context->companyId(),
        ];
        $sourceDeleted = array_key_exists('is_deleted', $attributes) ? (bool) $attributes['is_deleted'] : null;
        unset($attributes['is_deleted']);

        $row = $model::withoutEvents(function () use ($model, $match, $attributes, $insertOnly, $tenant, $sourceDeleted) {
            $existing = $match !== null ? $model::withTrashed()->where($match + $tenant)->first() : null;

            if ($existing === null) {
                $row = new $model();
                $row->forceFill(
                    $attributes + $insertOnly + $tenant + ($sourceDeleted !== null ? ['is_deleted' => $sourceDeleted] : [])
                );
                $row->saveQuietly();

                return $row;
            }

            $existing->forceFill($attributes);

            $deleted = $sourceDeleted !== null ? $this->mirroredDeletedState($existing, $sourceDeleted) : null;

            // Compared as bool: assigning `false` over a stored "0" would dirty the row on every run.
            if ($deleted !== null && $deleted !== (bool) $existing->is_deleted) {
                $existing->is_deleted = $deleted;
            }

            if ($existing->isDirty()) {
                $existing->saveQuietly();
            }

            return $existing;
        });

        if (method_exists($row, 'disableWorkflows')) {
            $row->disableWorkflows();
        }

        if ($sourceDeleted !== null) {
            $this->recordMirrorDeletion($row);
        }

        return $row;
    }

    /**
     * Only deletes the mirror made itself are undone by a source restore. A row deleted in Kanvas
     * carries no BRUSHCRAZY_DELETED_BY_MIRROR and stays deleted, or every sync would resurrect it.
     */
    protected function mirroredDeletedState(Model $existing, bool $sourceDeleted): bool
    {
        if ($sourceDeleted) {
            return true;
        }

        if (! $existing->is_deleted) {
            return false;
        }

        return ! $existing->get(CustomFieldEnum::BRUSHCRAZY_DELETED_BY_MIRROR->value);
    }

    protected function recordMirrorDeletion(Model $row): void
    {
        $key = CustomFieldEnum::BRUSHCRAZY_DELETED_BY_MIRROR->value;

        if ($row->wasRecentlyCreated) {
            if ($row->is_deleted) {
                $row->set($key, true);
            }

            return;
        }

        if (! $row->wasChanged('is_deleted')) {
            return;
        }

        if ($row->is_deleted) {
            $row->set($key, true);

            return;
        }

        $row->del($key);
    }

    /**
     * Reads the stored custom fields of every row preloadMirroredIds() resolved, so
     * writeMirroredFields() compares a whole chunk against one query.
     *
     * @param  class-string<Model>  $model
     */
    protected function preloadMirroredFields(string $model, CustomFieldEnum $field): void
    {
        $this->mirroredFieldValues[$model] = $this->loadMirroredFields(
            $model,
            $this->context->companyId(),
            array_values($this->mirroredIdMaps[$model . '|' . $field->value] ?? [])
        );
    }

    /**
     * Null deletes the field, so a value cleared at the source is cleared in Kanvas too. A value
     * equal to the stored one is skipped: set() costs two firstOrCreate, an updateOrCreate and a
     * Redis write per field, on every row of every run.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function writeMirroredFields(Model $row, array $fields): void
    {
        $stored = $this->storedMirroredFields($row);

        foreach ($fields as $name => $value) {
            if ($value === null) {
                if (array_key_exists($name, $stored)) {
                    $row->del($name);
                    unset($stored[$name]);
                }

                continue;
            }

            $storedValue = self::storedFieldValue($value);

            if (($stored[$name] ?? null) === $storedValue) {
                continue;
            }

            $row->set($name, $value);
            $stored[$name] = $storedValue;
        }

        $this->rememberMirroredFields($row, $stored);
    }

    protected function storedMirroredField(Model $row, string $name): ?string
    {
        return $this->storedMirroredFields($row)[$name] ?? null;
    }

    /**
     * Rows outside a preload (the webhook path) are read on demand and only the last one is kept,
     * so a long run without preloads does not grow the cache row by row.
     *
     * @return array<string, string|null>
     */
    protected function storedMirroredFields(Model $row): array
    {
        $model = $row::class;
        $id = (int) $row->getKey();

        if (isset($this->mirroredFieldValues[$model][$id])) {
            return $this->mirroredFieldValues[$model][$id];
        }

        $key = $model . '|' . $id;

        if ($this->lastMirroredFields !== null && $this->lastMirroredFields['key'] === $key) {
            return $this->lastMirroredFields['values'];
        }

        $values = $row->wasRecentlyCreated
            ? []
            : $this->loadMirroredFields($model, (int) ($row->companies_id ?? 0), [$id])[$id] ?? [];

        $this->rememberMirroredFields($row, $values);

        return $values;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function rememberMirroredFields(Model $row, array $values): void
    {
        $model = $row::class;
        $id = (int) $row->getKey();

        if (isset($this->mirroredFieldValues[$model])) {
            $this->mirroredFieldValues[$model][$id] = $values;

            return;
        }

        $this->lastMirroredFields = ['key' => $model . '|' . $id, 'values' => $values];
    }

    /**
     * Matched the way HasCustomFields::set() matches its row (company, class, entity, name), with
     * no is_deleted filter, so "stored" means "set() would update it".
     *
     * @param  class-string<Model>  $model
     * @param  array<int, int>  $ids
     * @return array<int, array<string, string|null>>
     */
    private function loadMirroredFields(string $model, int $companyId, array $ids): array
    {
        $values = array_fill_keys($ids, []);

        if ($ids === []) {
            return $values;
        }

        DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $companyId)
            ->where('model_name', $model)
            ->whereIn('entity_id', $ids)
            ->orderBy('id')
            ->get(['entity_id', 'name', 'value'])
            ->each(function ($field) use (&$values) {
                $entityId = (int) $field->entity_id;
                $name = (string) $field->name;

                // set() updates the first row it matches, so the oldest duplicate is the live one.
                if (! array_key_exists($name, $values[$entityId] ?? [])) {
                    $values[$entityId][$name] = $field->value !== null ? (string) $field->value : null;
                }
            });

        return $values;
    }

    /**
     * The form set() leaves in the `value` column: the Json cast encodes arrays and the query
     * builder binds booleans as 0/1.
     */
    private static function storedFieldValue(mixed $value): string
    {
        $value = Str::jsonToArray($value);

        return match (true) {
            is_array($value) => (string) json_encode($value),
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
