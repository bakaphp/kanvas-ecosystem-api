<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Referrals\Actions\ProcessReferralCodeRedemptionAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ProcessReferralCodeRedemptionActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Order $order, Apps $app, $integrationCompany, $additionalParams) {
                $user = $order->user;

                // Process referral code redemption for this order
                $action = new ProcessReferralCodeRedemptionAction($order, $user);
                $processedRedemptions = $action->execute();

                return [
                    'result' => true,
                    'processed_redemptions' => count($processedRedemptions),
                    'redemptions' => $processedRedemptions,
                ];
            }
        );
    }
}
