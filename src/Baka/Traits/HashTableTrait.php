<?php

declare(strict_types=1);

namespace Baka\Traits;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
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
    public function set(string $key, mixed $value, bool|int $isPublic = 0): bool
    {
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
        $this->settingsModel->save();

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
            $this->set($setting['name'], $setting['data'], $isPublic);
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
    public function getAllSettings(bool $onlyPublicSettings = false, bool $publicFormat = false): array
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
    public function get(string $key): mixed
    {
        $this->createSettingsModel();
        $value = $this->getSettingsByKey($key);

        if (is_object($value)) {
            return $value->value;
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
