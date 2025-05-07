<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OfferLogix\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\OfferLogix\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class OfferLogixHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $companySourceId = $this->data['company_source_id'] ?? null;

        if (! $companySourceId) {
            throw new ValidationException('OfferLogix Company source code not found');
        }

        return $this->company->set(ConfigurationEnum::COMPANY_SOURCE_ID->value, $companySourceId);
    }
}
