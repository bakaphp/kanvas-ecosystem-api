<?php

declare(strict_types=1);

namespace Kanvas\Connectors\LicensePlateExtractor\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ConfigurationEnum;
use Kanvas\Connectors\LicensePlateExtractor\Enums\ProviderEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class LicensePlateExtractorHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $providerRaw = (string) ($this->data['provider'] ?? ProviderEnum::GEMINI->value);
        $provider = ProviderEnum::tryFrom($providerRaw)
            ?? throw new ValidationException('Unsupported license plate provider: ' . $providerRaw);

        $this->app->set(ConfigurationEnum::PROVIDER->value, $provider->value);

        if (! empty($this->data['model'])) {
            $this->app->set(ConfigurationEnum::MODEL->value, (string) $this->data['model']);
        }

        if (! empty($this->data['region'])) {
            $this->app->set(ConfigurationEnum::REGION->value, (string) $this->data['region']);
        }

        if (isset($this->data['min_confidence'])) {
            $this->app->set(ConfigurationEnum::MIN_CONFIDENCE->value, (string) $this->data['min_confidence']);
        }

        if ($provider === ProviderEnum::PLATE_RECOGNIZER) {
            $apiKey = (string) ($this->data['api_key'] ?? '');
            if ($apiKey === '') {
                throw new ValidationException('PlateRecognizer API key is required');
            }
            $this->app->set(ConfigurationEnum::API_KEY->value, $apiKey);
        }

        return $this->app->get(ConfigurationEnum::PROVIDER->value) === $provider->value;
    }
}
