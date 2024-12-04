<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ConfigurationException;
use Illuminate\Support\Facades\Schema;

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

    public function hasAppsIdColumn(): bool
    {
        return Schema::hasColumn($this->getTable(), 'apps_id');

    }

    /**
     * Set the settings.
     */
    public function set(string $key, mixed $value, bool|int $isPublic = 0, ?Apps $app = null): bool
    {
        $app = $app ?? app(Apps::class);
        $this->createSettingsModel();

        if ($value === null) {
            return false;
        }

        if (! is_object($this->settingsModel)) {
            throw new ConfigurationException(
                '
                ModelSettingsTrait need to have a settings model configure,
                check the model setting exists for this class' . get_class($this)
            );
        }

        //if we don't find it we create it
        if (empty($this->settingsModel = $this->getSettingsByKey($key))) {
            /**
             * @todo this is stupid look for a better solution
             */
            $this->createSettingsModel();
            $this->settingsModel->{$this->getSettingsPrimaryKey()} = $this->getKey();
        }
        $value = Str::isJson($value) ? json_decode($value, true) : $value;
        $this->settingsModel->name = $key;
        $this->settingsModel->value = $value;
        $this->settingsModel->is_public = (int) $isPublic;
        if ($app && $this->hasAppsIdColumn()) {
            $this->settingsModel->apps_id = $app->getId();
        }
        $this->settingsModel->save();

        return true;
    }

    /**
     * @param array<array-key, array{name: string, data: mixed}> $settings
     * @throws ConfigurationException
     */
    public function setAll(array $settings, bool|int $isPublic = false, ?Apps $app = null): bool
    {
        $app = $app ?? app(Apps::class);
        if (empty($settings)) {
            return false;
        }

        foreach ($settings as $setting) {
            $isPublic = $setting['public'] ?? $isPublic;
            $this->set($setting['name'], $setting['data'], $isPublic, $app);
        }

        return true;
    }

    /**
     * Get the settings by its key.
     */
    protected function getSettingsByKey(string $key, ?Apps $app = null): mixed
    {
        $app = $app ?? app(Apps::class);
        return $this->settingsModel
            ->where($this->getSettingsPrimaryKey(), $this->getKey())
            ->when($this->hasAppsIdColumn(), function ($query) use ($app) {
                return $query->where('apps_id', $app->getId())
                        ->orWhereNull(column: 'apps_id');
            })
            ->where('name', $key)
            ->first();
    }

    /**
     * Get all the setting of a given record.
     */
    public function getAllSettings(bool $onlyPublicSettings = false, bool $publicFormat = false, ?Apps $app = null): array
    {
        $this->createSettingsModel();
        $app = $app ?? app(Apps::class);
        $allSettings = [];
        if ($onlyPublicSettings) {
            $settings = $this->settingsModel::where($this->getSettingsPrimaryKey(), $this->getId())
                ->when($this->hasAppsIdColumn(), function ($query) use ($app) {
                    return $query->where('apps_id', $app->getId())
                            ->orWhereNull(column: 'apps_id');

                })
                ->isPublic()
                ->get();
        } else {
            $settings = $this->settingsModel::where($this->getSettingsPrimaryKey(), $this->getId())
            ->when($app, function ($query) use ($app) {
                return $query->where('apps_id', $app->getId())
                        ->orWhereNull(column: 'apps_id');
            })
            ->get();
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

    public function getAll(bool $onlyPublicSettings = false, bool $publicFormat = false): array
    {
        return $this->getAllSettings($onlyPublicSettings, $publicFormat);
    }

    /**
     * Get the settings base on the key.
     */
    public function get(string $key, mixed $defaultValue = null): mixed
    {
        $this->createSettingsModel();
        $value = $this->getSettingsByKey($key);

        if (is_object($value)) {
            return $value->value;
        }

        return $defaultValue;
    }

    /**
     * Delete element.
     */
    public function deleteHash(string $key, ?Apps $app = null): bool
    {
        $app = $app ?? app(Apps::class);
        $this->createSettingsModel();
        if ($record = $this->getSettingsByKey($key, $app)) {
            return $record->delete();
        }

        return false;
    }

    public function del(string $key, ?Apps $app = null): bool
    {
        return $this->deleteHash($key, $app);
    }

    public static function getByCustomField(string $name, mixed $value, ?Apps $app = null): ?Model
    {
        $app = $app ?? app(Apps::class);
        $instance = new static();
        $settingsTable = $instance->getSettingsTable();
        $foreignKey = $instance->getSettingsForeignKey();

        return self::join($settingsTable, $instance->getTable() . '.id', '=', $settingsTable . '.' . $foreignKey)
            ->where($settingsTable . '.name', $name)
            ->where($settingsTable . '.value', $value)
            ->where($instance->getTable() . '.is_deleted', 0)
            ->when($app, function ($query) use ($app, $settingsTable) {
                return $query->where($settingsTable . '.apps_id', $app->getId());
            })
            ->select($instance->getTable() . '.*')
            ->first();
    }
}
