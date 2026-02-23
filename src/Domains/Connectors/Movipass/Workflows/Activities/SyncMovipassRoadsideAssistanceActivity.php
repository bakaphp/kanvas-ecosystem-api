<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Actions\AttachRoadsideAssistancePhotosAction;
use Kanvas\Connectors\Movipass\Actions\PrepareRoadsideAssistanceCaseAction;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class SyncMovipassRoadsideAssistanceActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                if ($order->orderType->name !== OrderTypeEnum::ROADSIDE_ASSISTANCE->value) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not a roadside assistance type',
                    ];
                }

                $eventName = $additionalParams['currentEventTypeName'] ?? null;

                if ($eventName === WorkflowEnum::CREATED->value) {
                    return $this->handleCreated($order, $app);
                }

                if ($eventName === WorkflowEnum::STATUS_TRANSITION->value) {
                    return $this->handleStatusTransition($order, $params['to_status'] ?? null);
                }

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'No roadside assistance processing required for this event',
                ];
            },
            company: $order->company,
        );
    }

    private function handleCreated($order, $app): array
    {
        $metadata = new PrepareRoadsideAssistanceCaseAction()->execute(
            $order->metadata ?? [],
            $order->user,
        );

        $order->metadata = $metadata;
        $order->saveQuietly();

        $photos = $metadata['assistance_case']['photos'] ?? [];
        if ($photos !== []) {
            new AttachRoadsideAssistancePhotosAction()->execute($order, $photos, $app);
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Roadside assistance case prepared',
            'data' => $order->toArray(),
            'response' => $order->toArray(),
        ];
    }

    private function handleStatusTransition($order, ?string $toStatus): array
    {
        if (! is_string($toStatus) || $toStatus === '') {
            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'No status to transition to',
            ];
        }

        $metadata = $order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        if ($assistanceCase !== []) {
            $assistanceCase['status'] = $toStatus;
            $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();

            $order->metadata = [
                ...$metadata,
                'assistance_case' => $assistanceCase,
                'data' => [
                    ...($metadata['data'] ?? []),
                    'assistance_case' => $assistanceCase,
                ],
            ];
            $order->saveQuietly();
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Roadside assistance status transitioned to ' . $toStatus,
            'data' => $order->toArray(),
            'response' => $order->toArray(),
        ];
    }
}
