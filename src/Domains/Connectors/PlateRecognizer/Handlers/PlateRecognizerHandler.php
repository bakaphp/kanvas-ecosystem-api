<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PlateRecognizer\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\PlateRecognizer\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class PlateRecognizerHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiKey = $this->data['api_key'] ?? null;
        $region = $this->data['region'] ?? null;

        if ($apiKey === null) {
            throw new ValidationException('Plate Recognizer API key not found');
        }

        $this->app->set(ConfigurationEnum::API_KEY->value, $apiKey);

        if ($region !== null) {
            $this->app->set(ConfigurationEnum::APP_CAR_REGION->value, $region);
        }

        return $this->app->get(ConfigurationEnum::API_KEY->value) === $apiKey ;
    }
}
