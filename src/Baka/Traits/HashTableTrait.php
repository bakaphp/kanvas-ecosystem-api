<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Exceptions\ConfigurationException;

trait HashTableTrait
{
    protected ?Model $settingsModel = null;

    /**
     * Get the primary key of this model, this will only work on model with just 1 primary key.
     *
     * @return string
     */
    private function getSettingsPrimaryKey(): string
    {
        return $this->table . '_' . $this->getKeyName();
    }

    /**
     * Set the setting model.
     *
     * @return void
     */
    protected function createSettingsModel(): void
    {
        $class = get_class($this) . 'Settings';

        $this->settingsModel = new $class();
    }

    /**
     * Set the settings.
     *
     * @param string $key
     * @param mixed $value
     *
     * @return bool
     */
    public function set(string $key, mixed $value): bool
    {
        $this->createSettingsModel();

        if (!is_object($this->settingsModel)) {
            throw new ConfigurationException('ModelSettingsTrait need to have a settings model configure, check the model setting exists for this class' . get_class($this));
        }

        //if we don't find it we create it
        if (empty($this->settingsModel = $this->getSettingsByKey($key))) {
            /**
             * @todo this is stupid look for a better solution
             */
            $this->createSettingsModel();
            $this->settingsModel->{$this->getSettingsPrimaryKey()} = $this->getKey();
        }

        $this->settingsModel->name = $key;
        $this->settingsModel->value = !is_array($value) ? (string) $value : json_encode($value);
        $this->settingsModel->save();

        return true;
    }

    /**
     * Get the settings by its key.
     *
     * @return mixed
     */
    protected function getSettingsByKey(string $key): mixed
    {
        return $this->settingsModel
            ->where($this->getSettingsPrimaryKey(), $this->getKey())
            ->where('name', $key)->first();
    }

    /**
     * Get all the setting of a given record.
     *
     * @return array
     */
    public function getAllSettings(): array
    {
        $this->createSettingsModel();

        $allSettings = [];
        $settings = $this->settingsModel::where($this->getSettingsPrimaryKey(), $this->getId())->get();

        foreach ($settings as $setting) {
            $allSettings[$setting->name] = !Str::isJson($setting->value) ? $setting->value : json_decode($setting->value, true);
        }

        return $allSettings;
    }

    /**
     * Get the settings base on the key.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key): mixed
    {
        $this->createSettingsModel();
        $value = $this->getSettingsByKey($key);

        if (is_object($value)) {
            return !Str::isJson($value->value) ? $value->value : json_decode($value->value, true);
        }

        return null;
    }

    /**
     * Delete element.
     *
     * @param string $key
     *
     * @return bool
     */
    public function deleteHash(string $key): bool
    {
        $this->createSettingsModel();
        if ($record = $this->getSettingsByKey($key)) {
            return $record->destroy();
        }

        return false;
    }
}
