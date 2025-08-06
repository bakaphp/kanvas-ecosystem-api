<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\UniversalAssistance\Handlers\UniversalAssistanceHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\KanvasActivity;

class CreateUniversalAssistanceVoucherActivity extends KanvasActivity
{
    /**
     * Create a travel insurance voucher
     */
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        $handler = new UniversalAssistanceHandler($app, $order);
        
        // Get voucher data from params or order metadata
        $voucherData = $params['voucher_data'] ?? $order->metadata['universal_assistance']['voucher_data'] ?? [];
        
        // Get applicant from order
        $applicant = $order->peoples()->first();
        if (!$applicant) {
            throw new ValidationException('No applicant found for voucher creation');
        }
        
        return $handler->handleVoucherCreation($voucherData, $applicant);
    }
}
