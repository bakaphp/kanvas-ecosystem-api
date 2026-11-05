<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VAuto\Handlers;

use Baka\Support\Str;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\VAuto\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class VAutoHandler extends BaseIntegration
{
    /**
     * vAuto's appraisal service has no read-only call to probe credentials with, so setup stores
     * them and the first appraisal push is the validation.
     */
    #[Override]
    public function setup(): bool
    {
        $accessId = Str::trimToNull((string) ($this->data['access_id'] ?? ''));
        $secretKey = Str::trimToNull((string) ($this->data['secret_key'] ?? ''));
        $entityId = Str::trimToNull((string) ($this->data['entity_id'] ?? ''));

        if ($accessId === null || $secretKey === null || $entityId === null) {
            throw new ValidationException('vAuto access id, secret key and entity id are required');
        }

        $this->company->set(ConfigurationEnum::ACCESS_ID->value, $accessId);
        $this->company->set(ConfigurationEnum::SECRET_KEY->value, $secretKey);
        $this->company->set(ConfigurationEnum::ENTITY_ID->value, $entityId);

        $userName = Str::trimToNull((string) ($this->data['user_name'] ?? ''));
        if ($userName !== null) {
            $this->company->set(ConfigurationEnum::USER_NAME->value, $userName);
        }

        return true;
    }

    #[Override]
    protected function companySettingKeys(): array
    {
        return [
            ConfigurationEnum::ACCESS_ID->value,
            ConfigurationEnum::SECRET_KEY->value,
            ConfigurationEnum::ENTITY_ID->value,
            ConfigurationEnum::USER_NAME->value,
            ConfigurationEnum::WSDL_URL->value,
        ];
    }
}
