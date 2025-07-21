<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Redis;
use Kanvas\Exceptions\ConfigurationException;

/**
 * @todo implement redis hashtable for speed
 */
trait HashTableTrait
{
    protected ?Model $settingsModel = null;

    /**
     * Get the primary key of this model, this will only work on model with just 1 primary key.
     */
    protected function getSettingsPrimaryKey(): string
    {
        return $this->table . '_' . $this->getKeyName();
    }

    /**
     * Get the settings primary key for faster Redis access.
     */
    public function getSettingsRedisPrimaryKey(): string
    {
        return Str::simpleSlug(get_class($this) . '_settings_' . $this->getKey());
    }

    /**
     * Set the setting model.
     */
    protected function createSettingsModel(): void
    {
        $class = get_class($this) . 'Settings';

        $this->settingsModel = new $class();
    }

    /**
     * Get the foreign key used in the settings table for this model.
     */
    protected function getSettingsForeignKey(): string
    {
        return $this->getTable() === 'companies' ? 'companies_id' : 'apps_id';
    }

    protected function getSettingsTable(): string
    {
        return $this->getTable() . '_settings';
    }

    /**
     * Set the settings.
     */
    public function set(string $key, mixed $value, bool|int $isPublic = 0): bool
    {
        $this->createSettingsModel();

        if ($value === null) {
            return false;
        }

        if (! is_object($this->settingsModel)) {
            throw new ConfigurationException(
                'ModelSettingsTrait need to have a settings model configure, check the model setting exists for this class' . get_class($this)
            );
        }

        // Set in Redis first
        $this->setInRedis($key, $value);

        $settingsModelClass = get_class($this->settingsModel);
        $primaryKey = $this->getSettingsPrimaryKey();

        $value = Str::isJson($value) ? json_decode($value, true) : $value;

        // Use upsert for atomic insert/update
        $settingsModelClass::upsert(
            [
                [
                    $primaryKey => $this->getKey(),
                    'name' => $key,
                    'value' => is_array($value) ? json_encode($value) : $value,
                    'is_public' => (int) $isPublic,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ],
            [$primaryKey, 'name'], // unique columns
            ['value', 'is_public', 'updated_at'] // columns to update on conflict
        );

        return true;
    }

    /**
     * @param array<array-key, array{name: string, data: mixed}> $settings
     * @throws ConfigurationException
     */
    public function setAll(array $settings, bool|int $isPublic = false): bool
    {
        if (empty($settings)) {
            return false;
        }

        foreach ($settings as $setting) {
            $isPublic = $setting['public'] ?? $isPublic;
            $value = $setting['data'] ?? $setting['value'] ?? null;
            if ($value === null) {
                continue;
            }

            $this->set($setting['name'], $value, $isPublic);
        }

        return true;
    }

    /**
     * Get the settings by its key.
     */
    protected function getSettingsByKey(string $key): mixed
    {
        return $this->settingsModel
            ->where($this->getSettingsPrimaryKey(), $this->getKey())
            ->where('name', $key)->first();
    }

    /**
     * Get all the setting of a given record.
     */
    public function getAllSettings(bool $onlyPublicSettings = false, bool $publicFormat = false, bool $fromRedis = true): array
    {
        if ($fromRedis && ! empty($listOfSettings = $this->getAllFromRedis())) {
            return $this->formatSettingsOutput($listOfSettings, $onlyPublicSettings, $publicFormat);
        }

        $this->createSettingsModel();

        $allSettings = [];
        if ($onlyPublicSettings) {
            $settings = $this->settingsModel::where($this->getSettingsPrimaryKey(), $this->getId())
                ->isPublic()
                ->get();
        } else {
            $settings = $this->settingsModel::where($this->getSettingsPrimaryKey(), $this->getId())->get();
        }

        foreach ($settings as $setting) {
            if (! $publicFormat) {
                $allSettings[$setting->name] = $setting->value;
            } else {
                $allSettings[$setting->name] = [
                    'value' => $setting->value,
                    'public' => (bool) $setting->is_public,
                ];
            }
        }

        return $allSettings;
    }

    public function getAll(bool $onlyPublicSettings = false, bool $publicFormat = false, bool $fromRedis = true): array
    {
        return $this->getAllSettings($onlyPublicSettings, $publicFormat, $fromRedis);
    }

    /**
     * Get all the settings from Redis.
     */
    public function getAllFromRedis(): array
    {
        $fields = Redis::hGetAll(
            $this->getSettingsRedisPrimaryKey(),
        );

        $result = [];
        foreach ($fields as $key => $value) {
            // Skip cached null markers
            if ($value === '__NULL__') {
                continue;
            }
            $result[$key] = Str::jsonToArray($value);
        }

        return $result;
    }

    /**
     * Get all settings from Redis with pagination.
     */
    public function getAllSettingsFromRedisPaginated(int $limit = 25, int $page = 1): array
    {
        $keys = Redis::hKeys($this->getSettingsRedisPrimaryKey());

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
        $values = Redis::hMGet($this->getSettingsRedisPrimaryKey(), $paginatedKeys);

        // Combine keys and values into the final paginated result
        $paginatedResult = [];
        foreach ($paginatedKeys as $index => $key) {
            $paginatedResult[] = [
                'name' => $key,
                'value' => Str::jsonToArray($values[$index]),
                'entity_id' => $this->getKey(),
            ];
        }

        return [
            'results' => $paginatedResult,
            'total' => $total,
            'per_page' => $perPage,
        ];
    }

    /**
     * Format settings output based on requirements.
     */
    protected function formatSettingsOutput(array $settings, bool $onlyPublicSettings = false, bool $publicFormat = false): array
    {
        // If no filtering or formatting is needed, return as-is
        if (! $onlyPublicSettings && ! $publicFormat) {
            return $settings;
        }

        // Since Redis doesn't currently store is_public info, we need to fallback to DB
        // for public filtering. This keeps the existing Redis format intact.
        return $this->getAllSettings($onlyPublicSettings, $publicFormat, false);
    }

    /**
     * Get the settings base on the key.
     */
    public function get(string $key, mixed $defaultValue = null): mixed
    {
        $redisKey = $this->getSettingsRedisPrimaryKey();
        $value = Redis::hGet($redisKey, $key);

        // If key exists in Redis
        if ($value !== false) {
            // Handle cached "not found" state
            if ($value === '__NULL__') {
                return $defaultValue;
            }

            return Str::jsonToArray($value);
        }

        // Key doesn't exist in Redis, check database
        $this->createSettingsModel();
        $setting = $this->getSettingsByKey($key);

        if (is_object($setting)) {
            // Cache the value in Redis for future access
            $this->setInRedis($key, $setting->value);

            return $setting->value;
        }

        // Cache the "not found" state to prevent future database queries
        Redis::hSet($redisKey, $key, '__NULL__');

        return $defaultValue;
    }

    /**
     * Get setting from Redis.
     */
    protected function getFromRedis(string $key): mixed
    {
        $value = Redis::hGet(
            $this->getSettingsRedisPrimaryKey(),
            $key
        );

        return Str::jsonToArray($value);
    }

    /**
     * Set setting in Redis.
     */
    protected function setInRedis(string $key, mixed $value): bool
    {
        return (bool) Redis::hSet(
            $this->getSettingsRedisPrimaryKey(),
            $key,
            ! is_array($value) ? $value : json_encode($value)
        );
    }

    /**
     * Delete element.
     */
    public function deleteHash(string $key): bool
    {
        $this->createSettingsModel();
        if ($record = $this->getSettingsByKey($key)) {
            // Remove from Redis
            Redis::hDel(
                $this->getSettingsRedisPrimaryKey(),
                $key
            );

            return $record->delete();
        }

        return false;
    }

    public function del(string $key): bool
    {
        return $this->deleteHash($key);
    }

    /**
     * Delete all settings from Redis.
     */
    protected function deleteAllSettingsFromRedis(): bool
    {
        return (bool) Redis::del(
            $this->getSettingsRedisPrimaryKey(),
        );
    }

    /**
     * Remove all the settings from the entity.
     */
    public function deleteAllSettings(): bool
    {
        $this->deleteAllSettingsFromRedis();

        $this->createSettingsModel();
        $settings = $this->settingsModel::where($this->getSettingsPrimaryKey(), $this->getId())->get();

        foreach ($settings as $setting) {
            $setting->delete();
        }

        return true;
    }

    /**
     * If something happened to Redis and we need to re-insert all the settings
     * for this entity, we run this method.
     */
    public function reGenerateRedisSettings(): void
    {
        foreach ($this->getAllSettings(fromRedis: false) as $key => $value) {
            $this->setInRedis((string) $key, $value);
        }
    }

    /**
     * Re-cache settings (alias for reGenerateRedisSettings).
     */
    public function reCacheSettings(): void
    {
        $this->reGenerateRedisSettings();
    }

    public static function getByCustomField(string $name, mixed $value): ?Model
    {
        $instance = new static();
        $settingsTable = $instance->getSettingsTable();
        $foreignKey = $instance->getSettingsForeignKey();

        return self::join($settingsTable, $instance->getTable() . '.id', '=', $settingsTable . '.' . $foreignKey)
            ->where($settingsTable . '.name', $name)
            ->where($settingsTable . '.value', $value)
            ->where($instance->getTable() . '.is_deleted', 0)
            ->select($instance->getTable() . '.*')
            ->first();
    }
}
