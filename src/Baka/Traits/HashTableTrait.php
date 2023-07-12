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
     */
    private function getSettingsPrimaryKey(): string
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
     * Set the settings.
     */
    public function set(string $key, mixed $value): bool
    {
        $this->createSettingsModel();

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
        $this->settingsModel->name = $key;
        $this->settingsModel->value = $value; //! is_array($value) ? (string) $value : json_encode($value);
        $this->settingsModel->save();

        return true;
    }

    public function setAll(array $settings): bool
    {
        foreach ($settings as $value) {
            $this->set($value['name'], $value['data']);
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
     *
     * @param bool $all
     */
    public function getAllSettings(bool $onlyPublicSettings = false): array
    {
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
            $allSettings[$setting->name] = ! Str::isJson($setting->value)
                                            ? $setting->value
                                            : json_decode($setting->value, true);
        }

        return $allSettings;
    }

    public function getAll(bool $onlyPublicSettings = false): array
    {
        return $this->getAllSettings($onlyPublicSettings);
    }

    /**
     * Get the settings base on the key.
     */
    public function get(string $key): mixed
    {
        $this->createSettingsModel();
        $value = $this->getSettingsByKey($key);

        if (is_object($value)) {
            return ! Str::isJson($value->value)
                        ? $value->value
                        : json_decode($value->value, true);
        }

        return null;
    }

    /**
     * Delete element.
     */
    public function deleteHash(string $key): bool
    {
        $this->createSettingsModel();
        if ($record = $this->getSettingsByKey($key)) {
            return $record->delete();
        }

        return false;
    }

    public function del(string $key): bool
    {
        return $this->deleteHash($key);
    }
}
