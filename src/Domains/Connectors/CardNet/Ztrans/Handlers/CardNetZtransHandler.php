<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\Handlers;

use Baka\Support\Str;
use Kanvas\Connectors\CardNet\Ztrans\Enums\ConfigurationEnum;
use Kanvas\Connectors\CardNet\Ztrans\Services\RedirectReceiverService;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;

class CardNetZtransHandler extends BaseIntegration
{
    private const array REQUIRED_SETTINGS = [
        'base_url' => ConfigurationEnum::BASE_URL,
        'merchant_id' => ConfigurationEnum::MERCHANT_ID,
        'terminal_id' => ConfigurationEnum::TERMINAL_ID,
        'three_ds_base_url' => ConfigurationEnum::THREE_DS_BASE_URL,
        'three_ds_integrator_code' => ConfigurationEnum::THREE_DS_INTEGRATOR_CODE,
        'three_ds_api_key' => ConfigurationEnum::THREE_DS_API_KEY,
    ];

    private const array OPTIONAL_SETTINGS = [
        'three_ds_mode' => ConfigurationEnum::THREE_DS_MODE,
        'use_hold' => ConfigurationEnum::USE_HOLD,
        'refund_bearer_token' => ConfigurationEnum::REFUND_BEARER_TOKEN,
    ];

    #[Override]
    public function setup(): bool
    {
        $missing = array_filter(
            array_keys(self::REQUIRED_SETTINGS),
            fn (string $field): bool => $this->input($field) === null
        );

        if ($missing !== []) {
            throw new ValidationException('CardNet Ztrans setup requires: ' . implode(', ', $missing));
        }

        foreach ([...self::REQUIRED_SETTINGS, ...self::OPTIONAL_SETTINGS] as $field => $key) {
            $value = $this->input($field);

            if ($value !== null) {
                $this->app->set($key->value, $value);
            }
        }

        return true;
    }

    #[Override]
    public function saved(IntegrationsCompany $integrationCompany): void
    {
        new RedirectReceiverService()->ensure($this->app, $this->company);
    }

    private function input(string $field): ?string
    {
        return Str::trimToNull((string) ($this->data[$field] ?? ''));
    }
}
