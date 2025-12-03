<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Loyalty\Actions\AssignLoyaltyProgramAction;
use Kanvas\Souk\Loyalty\Actions\AwardPointsAction;
use Kanvas\Souk\Loyalty\Actions\CheckOfferTriggersAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ProcessOrderLoyaltyActivity extends KanvasActivity
{
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

                // Step 1: Assign loyalty program if user doesn't have one
                $assignLoyaltyProgram = new AssignLoyaltyProgramAction($user, $app);
                $membership = $assignLoyaltyProgram->execute();

                // If no membership was created/found, user is not eligible
                if (! $membership) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'User is not eligible for any loyalty program',
                        'order_id' => $order->getId(),
                        'user_id' => $user->getId(),
                    ]);
                }

                // Step 2: Award points for this order
                $awardPoints = new AwardPointsAction($order, $membership);
                $orderLoyaltyPoints = $awardPoints->execute();

                // Refresh membership to get updated points
                $membership->refresh();

                // Step 3: Check for triggered offers
                $checkOfferTriggers = new CheckOfferTriggersAction($user, $order);
                $triggeredOffers = $checkOfferTriggers->execute();

                return [
                    'result' => true,
                    'message' => 'Loyalty program assigned and points awarded successfully',
                    'order_id' => $order->getId(),
                    'user_id' => $user->getId(),
                    'membership_id' => $membership->getId(),
                    'loyalty_program_id' => $membership->loyalty_programs_id,
                    'loyalty_tier_id' => $membership->loyalty_tiers_id,
                    'points_earned' => $orderLoyaltyPoints->points_earned,
                    'lifetime_points' => $membership->lifetime_points,
                    'current_points' => $membership->current_points,
                    'triggered_offers' => array_map(fn ($assignment) => [
                        'offer_id' => $assignment->loyalty_offers_id,
                        'offer_name' => $assignment->loyaltyOffer->name ?? null,
                        'offer_type' => $assignment->loyaltyOffer->offer_type ?? null,
                        'assignment_id' => $assignment->getId(),
                    ], $triggeredOffers),
                    'offers_count' => count($triggeredOffers),
                ];
            },
            company: $order->company,
        );
    }
}
