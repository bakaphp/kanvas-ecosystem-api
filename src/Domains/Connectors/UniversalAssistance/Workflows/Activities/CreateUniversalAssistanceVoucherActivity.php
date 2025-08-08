<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\UniversalAssistance\Handlers\UniversalAssistanceHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CreateUniversalAssistanceVoucherActivity extends KanvasActivity
{
    /**
     * Create a travel insurance voucher
     */
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::UNIVERSAL_ASSISTANCE,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                $handler = new UniversalAssistanceHandler($app, $order);

                // Get voucher data from params or order metadata
                $voucherData = $params['voucher_data'] ?? $order->metadata['universal_assistance']['voucher_data'] ?? [];

                // Get applicant from order
                $applicant = $order->peoples()->first();
                if (! $applicant) {
                    throw new ValidationException('No applicant found for voucher creation');
                }

                return $handler->handleVoucherCreation($voucherData, $applicant);
            },
            company: $order->company,
        );
    }
}
