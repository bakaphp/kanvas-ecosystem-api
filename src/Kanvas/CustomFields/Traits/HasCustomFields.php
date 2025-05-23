<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Traits;

use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Baka\Traits\HasSchemaAccessors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\CustomFields\Models\CustomFields;
use Kanvas\CustomFields\Models\CustomFieldsModules;
use Kanvas\Enums\AppEnums;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

trait HasCustomFields
{
    use HasSchemaAccessors;

    public array $customFields = [];

    public function customFields(): HasMany
    {
        return $this->hasMany(AppsCustomFields::class, 'entity_id', 'id')
                //->where('model_name', get_class($this))
                ->whereIn('model_name', [get_class($this), SystemModules::getLegacyNamespace(get_class($this))]) //allow legacy
                ->where('is_deleted', StateEnums::NO->getValue())
                ->when(isset($this->companies_id), function ($query) {
                    $query->where('companies_id', $this->companies_id);
                });
    }

    /**
     * Get the custom field primary key
     * for faster access via redis.
     */
    public function getCustomFieldPrimaryKey(): string
    {
        return Str::simpleSlug(get_class($this) . ' ' . $this->getKey());
    }

    /**
     * Get all custom fields of the given object.
     */
    public function getAllCustomFields(): array
    {
        return $this->getAll();
    }

    /**
     * Get all the custom fields.
     */
    public function getAll(bool $fromRedis = true): array
    {
        if ($fromRedis && ! empty($listOfCustomFields = $this->getAllFromRedis())) {
            return $listOfCustomFields;
        }

        $companyId = $this->companies_id ?? 0;
        $currentClass = get_class($this);
        $legacySystemModule = SystemModules::getLegacyNamespace($currentClass);
        $hasLegacySystemModule = $legacySystemModule !== $currentClass;

        // Build the query dynamically
        $query = 'SELECT name, value 
            FROM ' . DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields
            WHERE companies_id = ? AND entity_id = ?';

        $parameters = [$companyId, $this->getKey()];

        // Add model_name condition based on legacy status
        if ($hasLegacySystemModule) {
            $query .= ' AND (model_name = ? OR model_name = ?)';
            $parameters[] = $currentClass;
            $parameters[] = $legacySystemModule;
        } else {
            $query .= ' AND model_name = ?';
            $parameters[] = $currentClass;
        }

        $results = DB::select($query, $parameters);

        $listOfCustomFields = [];

        foreach ($results as $row) {
            $listOfCustomFields[$row->name] = Str::jsonToArray($row->value);
        }

        return $listOfCustomFields;
    }

    public function getCustomFieldsQueryBuilder(): Builder
    {
        return AppsCustomFields::where('entity_id', '=', $this->getKey())
            ->where('model_name', '=', static::class)
            ->where('is_deleted', '=', StateEnums::NO->getValue());
    }

    public function getAllCustomFieldsFromRedisPaginated(int $limit = 25, int $page = 1): array
    {
        $keys = Redis::hKeys($this->getCustomFieldPrimaryKey());

        if (empty($keys)) {
            return [
                'results' => [],
                'total' => 0,
                'per_page' => $limit,
            ];
        }

        $perPage = $limit;
        $total = count($keys);

        // Calculate start and end indexes for slicing
        $start = ($page - 1) * $perPage;
        $end = $start + $perPage - 1;

        // Slice the keys to get the current page's keys
        $paginatedKeys = array_slice($keys, $start, $perPage);

        // Fetch the values for the paginated keys using hMGet
        $values = Redis::hMGet($this->getCustomFieldPrimaryKey(), $paginatedKeys);

        // Combine keys and values into the final paginated result
        $paginatedResult = [];
        foreach ($paginatedKeys as $index => $key) {
            $paginatedResult[] = [
                'name' => $key,
                'value' => Str::jsonToArray($values[$index]),
            ];
        }

        return [
            'results' => $paginatedResult,
            'total' => $total,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get all the custom fields from redis.
     */
    public function getAllFromRedis(): array
    {
        $fields = Redis::hGetAll(
            $this->getCustomFieldPrimaryKey(),
        );

        foreach ($fields as $key => $value) {
            $fields[$key] = Str::jsonToArray($value);
        }

        return $fields;
    }

    /**
     * Get the Custom Field.
     */
    public function get(string $name, mixed $defaultValue = null): mixed
    {
        if ($value = $this->getFromRedis($name)) {
            return $value;
        }

        if ($field = $this->getCustomField($name)) {
            return $field->value;
        }

        return $defaultValue;
    }

    /**
     * Delete key from custom Fields.
     */
    public function del(string $name): bool
    {
        if ($field = $this->getCustomField($name)) {
            $field->delete();
            $this->clearCustomFieldsCacheIfNeeded();

            Redis::hDel(
                $this->getCustomFieldPrimaryKey(),
                $name
            );
        }

        return true;
    }

    /**
     * Get a Custom Field.
     */
    public function getCustomField(string $name): ?AppsCustomFields
    {
        return AppsCustomFields::where('companies_id', $this->companies_id ?? AppEnums::GLOBAL_COMPANY_ID->getValue())
                                ->whereIn('model_name', [get_class($this), SystemModules::getLegacyNamespace(get_class($this))]) //allow legacy
                                ->where('entity_id', $this->getKey())
                                ->where('name', $name)
                                ->first();
    }

    /**
     * Get custom field from redis.
     */
    protected function getFromRedis(string $name): mixed
    {
        $value = Redis::hGet(
            $this->getCustomFieldPrimaryKey(),
            $name
        );

        return Str::jsonToArray($value);
    }

    /**
     * Set value.
     */
    public function set(string $name, mixed $value): AppsCustomFields
    {
        $companyId = $this->companies_id ?? AppEnums::GLOBAL_COMPANY_ID->getValue();
        $modelName = get_class($this);
        $user = Auth::user();

        $value = Str::isJson($value) ? json_decode($value, true) : $value;
        $this->setInRedis($name, $value);

        $this->createCustomField($name);
        $this->clearCustomFieldsCacheIfNeeded();

        $customField = AppsCustomFields::updateOrCreate([
            'companies_id' => $companyId,
            'model_name' => $modelName,
            'entity_id' => $this->getKey(),
            'name' => $name,
        ], [
            'companies_id' => $companyId,
            'users_id' => $user !== null ? $user->getKey() : AppEnums::GLOBAL_USER_ID->getValue(),
            'model_name' => $modelName,
            'entity_id' => $this->getKey(),
            'label' => $name,
            'name' => $name,
            'value' => $value,
        ]);

        /**
         * @todo remove this once legacy is removed
         */
        try {
            $useLegacySystemModule = isset($this->app) && $this->app->get('legacy_custom_field_' . SystemModules::getSlugBySystemModuleNameSpace($modelName));
            if ($useLegacySystemModule) {
                $modelName = SystemModules::getLegacyNamespace($modelName);
                $customField = AppsCustomFields::updateOrCreate([
                    'companies_id' => $companyId,
                    'model_name' => $modelName,
                    'entity_id' => $this->getKey(),
                    'name' => $name,
                ], [
                    'companies_id' => $companyId,
                    'users_id' => $user !== null ? $user->getKey() : AppEnums::GLOBAL_USER_ID->getValue(),
                    'model_name' => $modelName,
                    'entity_id' => $this->getKey(),
                    'label' => $name,
                    'name' => $name,
                    'value' => $value,
                ]);
            }
        } catch (Throwable $e) {
            // do nothing
        }

        if (method_exists($this, 'fireWorkflow')) {
            $this->fireWorkflow(WorkflowEnum::CREATE_CUSTOM_FIELD->value);
        }

        return $customField;
    }

    /**
     * @throws ConfigurationException
     */
    public function setAllCustomFields(array $customFields, bool|int $isPublic = false): bool
    {
        if (empty($customFields)) {
            return false;
        }

        foreach ($customFields as $data) {
            $this->set($data['name'], $data['data']);
        }

        return true;
    }

    /**
     * Create a new Custom Fields.
     */
    public function createCustomField(string $name): CustomFields
    {
        $appsId = app(Apps::class)->id;
        $companiesId = Auth::user() !== null ? Auth::user()->getCurrentCompany()->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue();
        $textField = 1;
        // $cacheKey = Slug::generate(get_class($this) . '-' . $appsId . '-' . $name);
        //$lifetime = 604800;
        $user = Auth::user();

        $customFieldModules = CustomFieldsModules::firstOrCreate([
            'model_name' => get_class($this),
            'apps_id' => $appsId,
        ], [
            'model_name' => get_class($this),
            'name' => get_class($this),
            'apps_id' => $appsId,
        ]);

        $customField = CustomFields::firstOrCreate([
            'apps_id' => $appsId,
            'name' => $name,
            'custom_fields_modules_id' => $customFieldModules->getKey(),
        ], [
            'users_id' => $user !== null ? $user->getKey() : AppEnums::GLOBAL_USER_ID->getValue(),
            'companies_id' => $companiesId,
            'apps_id' => $appsId,
            'name' => $name,
            'label' => $name,
            'custom_fields_modules_id' => $customFieldModules->getKey(),
            'fields_type_id' => $textField,
        ]);

        return $customField;
    }

    /**
     * Set custom field in redis.
     */
    protected function setInRedis(string $name, mixed $value): bool
    {
        return (bool) Redis::hSet(
            $this->getCustomFieldPrimaryKey(),
            $name,
            ! is_array($value) ? $value : json_encode($value)
        );
    }

    /**
     * Create new custom fields.
     *
     * We never update any custom fields, we delete them and create them again, thats why we call deleteAllCustomFields before updates
     *
     * @return void
     */
    public function saveCustomFields(): bool
    {
        if ($this->hasCustomFields()) {
            foreach ($this->customFields as $key => $value) {
                if (! self::schemaHasColumn($key)) {
                    $this->set($key, $value);
                }
            }
        }
        if (method_exists($this, 'fireWorkflow')) {
            $this->fireWorkflow(WorkflowEnum::CREATE_CUSTOM_FIELDS->value);
        }

        if (method_exists($this, 'generateCustomFieldsLighthouseCache')) {
            //$this->clearLightHouseCacheJob();
        }

        return true;
    }

    /**
     * Remove all the custom fields from the entity.
     *
     */
    public function deleteAllCustomFields(): bool
    {
        $companyId = $this->companies_id ?? AppEnums::GLOBAL_COMPANY_ID->getValue();

        $this->deleteAllCustomFieldsFromRedis();
        $this->clearCustomFieldsCacheIfNeeded();

        return DB::statement('
            DELETE
                FROM ' . DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields
                    WHERE
                        companies_id = :companies_id
                        AND model_name = :model_name
                        AND entity_id = :entity_id', [
            'companies_id' => $companyId,
            'model_name' => get_class($this),
            'entity_id' => $this->getKey(),
        ]);
    }

    /**
     * Delete all custom fields from redis.
     */
    protected function deleteAllCustomFieldsFromRedis(): bool
    {
        return (bool) Redis::del(
            $this->getCustomFieldPrimaryKey(),
        );
    }

    /**
     * Set the custom field to update a custom field module.
     */
    public function setCustomFields(array $fields)
    {
        if (empty($fields)) {
            return;
        }

        /***
         * if column name exist this is a CustomFieldEntityInput
         * we need to convert it to key => value
         */
        if (isset($fields[0])) {
            $keyField = array_key_exists('name', $fields[0]) ? 'name' : (array_key_exists('key', $fields[0]) ? 'key' : null);

            if ($keyField) {
                $valueField = array_key_exists('data', $fields[0]) ? 'data' : (array_key_exists('value', $fields[0]) ? 'value' : null);

                if ($valueField) {
                    $fields = array_column($fields, $valueField, $keyField);
                }
            }
        }

        $this->customFields = $fields;
    }

    /**
     * Does this model have custom fields?
     */
    public function hasCustomFields(): bool
    {
        return ! empty($this->customFields);
    }

    /**
     * If something happened to redis
     * And we need to re insert all the custom fields
     * for this entity , we run this method.
     * @deprecated
     */
    public function reCacheCustomFields(): void
    {
        $this->reGenerateRedisCustomFields();
    }

    /**
     * Get a model from a custom field.
     */
    public static function getByCustomField(string $name, mixed $value, ?Companies $company = null): ?Model
    {
        return self::getByCustomFieldBuilder($name, $value, $company)->first();
    }

    public static function getByCustomFieldBuilder(string $name, mixed $value, ?Companies $company = null): Builder
    {
        $company = $company ? $company->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue();
        $table = (new static())->getTable();

        $query = self::join(DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields', 'apps_custom_fields.entity_id', '=', $table . '.id')
            ->where('apps_custom_fields.companies_id', $company)
            ->where('apps_custom_fields.model_name', static::class)
            ->where('apps_custom_fields.name', $name);

        if ($value !== null) {
            $query->where('apps_custom_fields.value', $value);
        }

        return $query->select($table . '.*');
    }

    protected function clearCustomFieldsCacheIfNeeded(): void
    {
        if (method_exists($this, 'clearLightHouseCache')) {
            //$this->clearLightHouseCacheJob();
        }
    }

    public function reGenerateRedisCustomFields(): void
    {
        foreach ($this->getAll(fromRedis: false) as $key => $value) {
            $this->setInRedis((string) $key, $value);
        }
    }
}
