<?php
declare(strict_types=1);

namespace Kanvas\CustomFields\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\CustomFields\Models\CustomFields;
use Kanvas\CustomFields\Models\CustomFieldsModules;
use Kanvas\Enums\AppEnums;
use Kanvas\Traits\HasSchemaAccessors;
use Kanvas\Utils\Str;

/**
 * Custom field class.
 */
trait HasCustomFields
{
    use HasSchemaAccessors;

    public array $customFields = [];

    /**
     * Get the custom field primary key
     * for faster access via redis.
     *
     * @return string
     */
    public function getCustomFieldPrimaryKey() : string
    {
        return Str::simpleSlug(get_class($this) . ' ' . $this->getKey());
    }

    /**
     * Get all custom fields of the given object.
     *
     * @return  array
     */
    public function getAllCustomFields() : array
    {
        return $this->getAll();
    }

    /**
     * Get all the custom fields.
     *
     * @return array
     */
    public function getAll() : array
    {
        if (!empty($listOfCustomFields = $this->getAllFromRedis())) {
            return $listOfCustomFields;
        }

        $companyId = $this->companies_id ?? 0;

        $results = DB::select('
            SELECT name, value
                FROM apps_custom_fields
                WHERE
                    companies_id = ?
                    AND model_name = ?
                    AND entity_id = ?
        ', [
            $companyId,
            get_class($this),
            $this->getKey()
        ]);

        $listOfCustomFields = [];

        foreach ($results as $row) {
            $listOfCustomFields[$row['name']] = Str::jsonToArray($row['value']);
        }

        return $listOfCustomFields;
    }

    /**
     * Get all the custom fields from redis.
     *
     * @return array
     */
    public function getAllFromRedis() : array
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
     *
     * @param string $name
     *
     * @return mixed
     */
    public function get(string $name)
    {
        if ($value = $this->getFromRedis($name)) {
            return $value;
        }

        if ($field = $this->getCustomField($name)) {
            return Str::jsonToArray($field->value);
        }

        return ;
    }

    /**
     * Delete key from custom Fields.
     *
     * @param string $name
     *
     * @return bool
     */
    public function del(string $name) : bool
    {
        if ($field = $this->getCustomField($name)) {
            $field->delete();

            Redis::hDel(
                $this->getCustomFieldPrimaryKey(),
                $name
            );
        }

        return true;
    }

    /**
     * Get a Custom Field.
     *
     * @param string $name
     *
     * @return AppsCustomFields|null
     */
    public function getCustomField(string $name) : ?AppsCustomFields
    {
        return AppsCustomFields::where('companies_id', $this->companies_id ?? AppEnums::GLOBAL_COMPANY_ID->getValue())
                                ->where('model_name', get_class($this))
                                ->where('entity_id', $this->getKey())
                                ->where('name', $name)
                                ->first();
    }

    /**
     * Get custom field from redis.
     *
     * @param string $name
     *
     * @return mixed
     */
    protected function getFromRedis(string $name) : mixed
    {
        $value = Redis::hGet(
            $this->getCustomFieldPrimaryKey(),
            $name
        );

        return Str::jsonToArray($value);
    }

    /**
     * Set value.
     *
     * @param string $name
     * @param mixed $value
     *
     * @return AppsCustomFields
     */
    public function set(string $name, $value) : AppsCustomFields
    {
        $companyId = $this->companies_id ?? AppEnums::GLOBAL_COMPANY_ID->getValue();
        $modelName = get_class($this);
        $user = Auth::user();

        $this->setInRedis($name, $value);

        $this->createCustomField($name);

        return AppsCustomFields::updateOrCreate([
            'companies_id' => $companyId,
            'model_name' => $modelName,
            'entity_id' => $this->getKey(),
            'name' => $name
        ], [
            'companies_id' => $companyId,
            'users_id' => $user !== null ? $user->getKey() : AppEnums::GLOBAL_USER_ID->getValue(),
            'model_name' => $modelName,
            'entity_id' => $this->getKey(),
            'label' => $name,
            'name' => $name,
            'value' => !is_array($value) ? $value : json_encode($value)
        ]);
    }

    /**
     * Create a new Custom Fields.
     *
     * @param string $name
     *
     * @return CustomFields
     */
    public function createCustomField(string $name) : CustomFields
    {
        $appsId = app(Apps::class)->id;
        $companiesId = Auth::user() !== null ? Auth::user()->defaultCompany()->first()->getKey() : AppEnums::GLOBAL_COMPANY_ID->getValue();
        $textField = 1;
        // $cacheKey = Slug::generate(get_class($this) . '-' . $appsId . '-' . $name);
        //$lifetime = 604800;
        $user = Auth::user();

        $customFieldModules = CustomFieldsModules::firstOrCreate([
            'model_name' => get_class($this),
            'apps_id' => $appsId
        ], [
            'model_name' => get_class($this),
            'name' => get_class($this),
            'apps_id' => $appsId
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
     *
     * @param string $name
     * @param mixed $value
     *
     * @return bool
     */
    protected function setInRedis(string $name, mixed $value) : bool
    {
        return (bool) Redis::hSet(
            $this->getCustomFieldPrimaryKey(),
            $name,
            !is_array($value) ? $value : json_encode($value)
        );
    }

    /**
     * Create new custom fields.
     *
     * We never update any custom fields, we delete them and create them again, thats why we call deleteAllCustomFields before updates
     *
     * @return void
     */
    public function saveCustomFields() : bool
    {
        if ($this->hasCustomFields()) {
            foreach ($this->customFields as $key => $value) {
                if (!self::schemaHasColumn($key)) {
                    $this->set($key, $value);
                }
            }
        }

        return true;
    }

    /**
     * Remove all the custom fields from the entity.
     *
     * @param  int $id
     *
     * @return bool
     */
    public function deleteAllCustomFields() : bool
    {
        $companyId = $this->companies_id ?? AppEnums::GLOBAL_COMPANY_ID->getValue();

        $this->deleteAllCustomFieldsFromRedis();

        return DB::statement('
            DELETE
                FROM apps_custom_fields
                    WHERE
                        companies_id = :companies_id
                        AND model_name = :model_name
                        AND entity_id = :entity_id', [
            'companies_id' => $companyId,
            'model_name' => get_class($this),
            'entity_id' => $this->getKey()
        ]);
    }

    /**
     * Delete all custom fields from redis.
     *
     * @return bool
     */
    protected function deleteAllCustomFieldsFromRedis() : bool
    {
        return (bool) Redis::del(
            $this->getCustomFieldPrimaryKey(),
        );
    }

    /**
     * Set the custom field to update a custom field module.
     *
     * @param array $fields
     */
    public function setCustomFields(array $fields)
    {
        $this->customFields = $fields;
    }

    /**
     * Does this model have custom fields?
     *
     * @return bool
     */
    public function hasCustomFields() : bool
    {
        return !empty($this->customFields);
    }

    /**
     * If something happened to redis
     * And we need to re insert all the custom fields
     * for this entity , we run this method.
     *
     * @return void
     */
    public function reCacheCustomFields() : void
    {
        foreach ($this->getAll() as $key => $value) {
            $this->setInRedis($key, $value);
        }
    }
}
