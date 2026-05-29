<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PayWay\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\PayWay\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class PayWayHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $token = $this->data['token'] ?? null;
        $colectorId = $this->data['colectorId'] ?? null;
        $usuarioOperacion = $this->data['usuarioOperacion'] ?? null;
        $encryptionKey = $this->data['encryptionKey'] ?? null;
        $baseUrl = $this->data['baseUrl'] ?? 'https://test.payway.sv';
        $verifyChargeAmount = $this->data['verifyChargeAmount'] ?? '1.00';
        $tokenizeCutoffLocalTime = $this->data['tokenizeCutoffLocalTime'] ?? '21:30';

        if (empty($token)) {
            throw new ValidationException('token is required for PayWay setup.');
        }

        if (empty($colectorId)) {
            throw new ValidationException('colectorId is required for PayWay setup.');
        }

        if (empty($usuarioOperacion)) {
            throw new ValidationException('usuarioOperacion is required for PayWay setup.');
        }

        if (empty($encryptionKey)) {
            throw new ValidationException('encryptionKey is required for PayWay setup.');
        }

        // All PayWay credentials are per-Company. No $app->set() fallback — would break tenant isolation.
        $this->company->set(ConfigurationEnum::PAYWAY_TOKEN->value, $token);
        $this->company->set(ConfigurationEnum::PAYWAY_COLECTOR_ID->value, $colectorId);
        $this->company->set(ConfigurationEnum::PAYWAY_USUARIO_OPERACION->value, $usuarioOperacion);
        $this->company->set(ConfigurationEnum::PAYWAY_ENCRYPTION_KEY->value, $encryptionKey);
        $this->company->set(ConfigurationEnum::PAYWAY_BASE_URL->value, $baseUrl);
        $this->company->set(ConfigurationEnum::PAYWAY_VERIFY_CHARGE_AMOUNT->value, $verifyChargeAmount);
        $this->company->set(ConfigurationEnum::PAYWAY_TOKENIZE_CUTOFF_LOCAL_TIME->value, $tokenizeCutoffLocalTime);

        return true;
    }
}
