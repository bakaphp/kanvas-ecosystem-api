<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class ReynoldsHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $required = [
            'username',
            'password',
            'endpoint',
            'dealer_number',
            'store_number',
            'area_number',
            'sender_name',
        ];

        foreach ($required as $field) {
            if (empty($this->data[$field])) {
                throw new ValidationException("Reynolds setup missing field: {$field}");
            }
        }

        $this->app->set(ConfigurationEnum::REYNOLDS_USERNAME->value, $this->data['username']);
        $this->app->set(ConfigurationEnum::REYNOLDS_PASSWORD->value, $this->data['password']);
        $this->app->set(ConfigurationEnum::REYNOLDS_ENDPOINT->value, $this->data['endpoint']);
        $this->app->set(ConfigurationEnum::REYNOLDS_SENDER_NAME->value, $this->data['sender_name']);
        $this->app->set(ConfigurationEnum::REYNOLDS_DEV_MODE->value, $this->data['dev_mode'] ?? false);

        $this->company->set(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value, $this->data['dealer_number']);
        $this->company->set(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value, $this->data['store_number']);
        $this->company->set(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value, $this->data['area_number']);
        $this->company->set(
            ConfigurationEnum::REYNOLDS_BUSINESS_UNIT_NAME->value,
            $this->data['business_unit_name'] ?? $this->company->name
        );

        return true;
    }
}
