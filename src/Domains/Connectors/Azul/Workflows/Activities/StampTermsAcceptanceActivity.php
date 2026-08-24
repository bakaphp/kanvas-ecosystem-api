<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Azul Stamp Terms Acceptance',
    description: 'Records WHEN and from which IP the customer accepted terms on a new order, for the audit '
        . 'trail. Writes to the order only; contacts nobody. Runs on order creation and skips silently '
        . 'on any other trigger, and skips when the order carries no terms acceptance.',
    integration: IntegrationsEnum::AZUL,
)]
class StampTermsAcceptanceActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::AZUL,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $eventName = $additionalParams['currentEventTypeName'] ?? null;

                if ($eventName !== WorkflowEnum::CREATED->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Event is not order creation, skipping',
                    ];
                }

                $acceptance = $order->metadata['data']['terms_acceptance'] ?? null;

                if (! is_array($acceptance)) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'No terms_acceptance in metadata, skipping',
                    ];
                }

                $order->metadata = [
                    ...$order->metadata ?? [],
                    'data' => [
                        ...$order->metadata['data'] ?? [],
                        'terms_acceptance' => [
                            ...$acceptance,
                            'accepted_at' => Carbon::now()->toIso8601String(),
                            'ip' => $acceptance['ip'] ?? $order->ip_address,
                        ],
                    ],
                ];
                $order->saveQuietly();

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Terms acceptance stamped',
                    'data' => [
                        'accepted_at' => $order->metadata['data']['terms_acceptance']['accepted_at'],
                        'version' => $order->metadata['data']['terms_acceptance']['version'] ?? null,
                    ],
                ];
            },
            company: $order->company,
        );
    }
}
