<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Handlers;

use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Connectors\BrushCrazy\Enums\StudioModeEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

class BrushCrazyHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $host = $this->data['brushcrazy_db_host'] ?? null;
        $database = $this->data['brushcrazy_db_database'] ?? null;

        if ($host === null || $database === null) {
            throw new ValidationException('BrushCrazy database host and database name are required.');
        }

        $studioMode = StudioModeEnum::tryFrom((string) ($this->data['brushcrazy_studio_mode'] ?? StudioModeEnum::COMPANY->value));

        if ($studioMode === null) {
            throw new ValidationException('brushcrazy_studio_mode must be either "company" or "branch".');
        }

        $this->app->set(ConfigurationEnum::BRUSHCRAZY_DB_HOST->value, $host);
        $this->app->set(ConfigurationEnum::BRUSHCRAZY_DB_DATABASE->value, $database);
        $this->app->set(ConfigurationEnum::BRUSHCRAZY_DB_PORT->value, $this->data['brushcrazy_db_port'] ?? '3306');
        $this->app->set(ConfigurationEnum::BRUSHCRAZY_DB_USERNAME->value, $this->data['brushcrazy_db_username'] ?? null);
        $this->app->set(ConfigurationEnum::BRUSHCRAZY_DB_PASSWORD->value, $this->data['brushcrazy_db_password'] ?? null);
        $this->app->set(ConfigurationEnum::BRUSHCRAZY_DEFAULT_TIMEZONE->value, $this->data['brushcrazy_default_timezone'] ?? 'America/Denver');
        $this->app->set(ConfigurationEnum::BRUSHCRAZY_DEFAULT_CURRENCY->value, $this->data['brushcrazy_default_currency'] ?? 'USD');

        if ($mediaBaseUrl = $this->data['brushcrazy_media_base_url'] ?? null) {
            $this->app->set(ConfigurationEnum::BRUSHCRAZY_MEDIA_BASE_URL->value, $mediaBaseUrl);
        }

        if ($sslCa = $this->data['brushcrazy_db_ssl_ca'] ?? null) {
            $this->app->set(ConfigurationEnum::BRUSHCRAZY_DB_SSL_CA->value, $sslCa);
        }

        // Locked in on first setup: changing it after an import would move every imported row to a
        // different company, which needs a full re-import rather than a settings edit.
        if (! $this->app->get(ConfigurationEnum::BRUSHCRAZY_STUDIO_MODE->value)) {
            $this->app->set(ConfigurationEnum::BRUSHCRAZY_STUDIO_MODE->value, $studioMode->value);
        }

        // Per company, not per app: the credentials are shared (all studios live in one source
        // database) but each studio has to be able to cut over — and stop being mirrored —
        // independently of the others.
        $this->company->set(ConfigurationEnum::BRUSHCRAZY_SYNC_ENABLED->value, true);

        return new Client($this->app)->testConnection();
    }
}
