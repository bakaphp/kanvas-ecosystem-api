<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\TypeSafe\Client;
use Kanvas\Connectors\TypeSafe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * Stored on the APP, not the company — same shape as Jina and Tavily, and with the same consequence:
 * a company admin running this setup replaces the key every company in the app reads with.
 *
 * Setup only proves the key works. It turns nothing on: every decision stays OFF until it is named
 * in `TYPESAFE_DECISIONS`.
 */
class TypeSafeHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $key = trim((string) ($this->data['api_key'] ?? ''));

        if ($key === '') {
            throw new ValidationException('TypeSafe API key is required.');
        }

        if (! Client::validateCredentials($key)) {
            throw new ValidationException('Invalid TypeSafe API key — TypeSafe rejected it.');
        }

        $this->app->set(ConfigurationEnum::TYPESAFE_API_KEY->value, $key);

        // Only when given. Re-running setup to rotate the key leaves the form's model field blank, and
        // writing the default there would silently move every threshold the app has tuned onto a
        // different calibration — the exact thing the pin exists to prevent. Unset reads as the pin
        // anyway, via TypeSafeConfigService::DEFAULT_MODEL.
        $model = trim((string) ($this->data['model'] ?? ''));

        if ($model !== '') {
            $this->app->set(ConfigurationEnum::TYPESAFE_MODEL->value, $model);
        }

        return true;
    }
}
