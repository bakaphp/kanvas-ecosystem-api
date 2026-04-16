<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Actions\AssignMechanicToOrderAction;
use Kanvas\Connectors\Movipass\Actions\AttachRoadsideAssistancePhotosAction;
use Kanvas\Connectors\Movipass\Actions\CancelMechanicAssignmentAction;
use Kanvas\Connectors\Movipass\Actions\CheckMechanicArrivalAction;
use Kanvas\Connectors\Movipass\Actions\GenerateRoadsideAssistancePinAction;
use Kanvas\Connectors\Movipass\Actions\NotifyAvailableMechanicsAction;
use Kanvas\Connectors\Movipass\Actions\PrepareRoadsideAssistanceCaseAction;
use Kanvas\Connectors\Movipass\Actions\ValidateRoadsideAssistancePinAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
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

                if ($eventName === WorkflowEnum::UPDATED->value) {
                    return $this->handleUpdated($order, $app);
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
        $user = Users::getById((int) $order->users_id);

        $metadata = new PrepareRoadsideAssistanceCaseAction()->execute(
            $order->metadata ?? [],
            $user,
        );

        $order->metadata = $metadata;
        $order->saveQuietly();

        $photos = $metadata['assistance_case']['photos'] ?? [];
        if ($photos !== []) {
            new AttachRoadsideAssistancePhotosAction()->execute($order, $photos, $app);
        }

        $mechanic = $metadata['assistance_case']['mechanic'] ?? null;
        if (empty($mechanic['user_id'])) {
            new NotifyAvailableMechanicsAction($order, $app, $user)->execute();
        }

        $order->refresh();

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Roadside assistance case prepared',
            'data' => $order->toArray(),
            'response' => $order->toArray(),
        ];
    }

    private function handleUpdated($order, AppInterface $app): array
    {
        $currentStatusSlug = $order->orderStatus?->slug;
        $metadata = $order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        $assignMechanicId = (int) ($assistanceCase['assign_mechanic_id'] ?? 0);
        if ($assignMechanicId > 0 && $currentStatusSlug === MovipassOrderStatusEnum::AWAITING_OPERATOR->slug()) {
            $mechanic = Users::getById($assignMechanicId);
            $user = Users::getById((int) $order->users_id);

            new AssignMechanicToOrderAction($order, $user, $mechanic)->execute();

            $order->refresh();

            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'Mechanic assigned and order transitioned to provider_assigned',
                'data' => $order->toArray(),
                'response' => $order->toArray(),
            ];
        }

        if ($currentStatusSlug === MovipassOrderStatusEnum::DISPATCHED->slug()) {
            $mechanicUserId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);

            if ($mechanicUserId > 0) {
                $mechanic = Users::getById($mechanicUserId);
                $arrived = new CheckMechanicArrivalAction($order, $mechanic)->execute();

                if ($arrived) {
                    $order->refresh();

                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Mechanic arrived on site, order transitioned to on_site',
                        'data' => $order->toArray(),
                        'response' => $order->toArray(),
                    ];
                }
            }
        }

        if (($assistanceCase['mechanic_cancel'] ?? false) === true) {
            $nonCancellableStatuses = [
                MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            ];
            if (in_array($currentStatusSlug, $nonCancellableStatuses, true)) {
                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Mechanic cancel ignored: order is already in a terminal status',
                ];
            }

            $mechanicUserId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);
            $mechanic = $mechanicUserId ? Users::getById($mechanicUserId) : null;

            if (! $mechanic instanceof Users) {
                return [
                    'order' => $order->getId(),
                    'status' => 'error',
                    'message' => 'No mechanic assigned to this order',
                ];
            }

            new CancelMechanicAssignmentAction(
                $order,
                $mechanic,
                $app,
            )->execute();

            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'Mechanic assignment cancelled, order returned to awaiting_operator',
            ];
        }

        if ($currentStatusSlug !== MovipassOrderStatusEnum::ON_SITE->slug()) {
            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'PIN validation only applies in on_site status',
            ];
        }

        $pinAttempt = $assistanceCase['pin_attempt'] ?? null;

        if ($pinAttempt === null || trim((string) $pinAttempt) === '') {
            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'No pin_attempt found in metadata, skipping',
            ];
        }

        try {
            new ValidateRoadsideAssistancePinAction(
                order: $order,
                pin: (string) $pinAttempt,
            )->execute();
        } catch (ValidationException $e) {
            $assistanceCase['pin_validation_error'] = $e->getMessage();
            $assistanceCase['pin_validation_attempted_at'] = Carbon::now()->toISOString();
            unset($assistanceCase['pin_attempt']);

            $this->saveAssistanceCaseMetadata($order, $metadata, $assistanceCase);

            return [
                'order' => $order->getId(),
                'status' => 'error',
                'message' => 'PIN validation failed: ' . $e->getMessage(),
            ];
        }

        // PIN is valid — clean up metadata and transition to SERVICE_IN_PROGRESS
        $assistanceCase['pin_validated_at'] = Carbon::now()->toISOString();
        $assistanceCase['status'] = MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug();
        $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();
        unset($assistanceCase['pin_attempt'], $assistanceCase['pin_validation_error']);
        $this->saveAssistanceCaseMetadata($order, $metadata, $assistanceCase);

        $order->transitionToStatus(
            Users::getById((int) $order->users_id),
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug(),
        );

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'PIN validated, order transitioned to service_in_progress',
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
            $timestamp = $this->getFormattedTimestamp();

            $assistanceCase['status'] = $toStatus;
            $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();

            match ($toStatus) {
                MovipassOrderStatusEnum::AWAITING_OPERATOR->slug() => $assistanceCase['awaiting_operator_at'] = $timestamp,
                MovipassOrderStatusEnum::PROVIDER_ASSIGNED->slug() => (function () use ($order, &$assistanceCase, $timestamp) {
                    $assistanceCase['provider_assigned_at'] = $timestamp;
                    $pin = new GenerateRoadsideAssistancePinAction($order)->execute();
                    $order->refresh();
                    $assistanceCase = array_merge($assistanceCase, [
                        'pin_hash' => $order->metadata['assistance_case']['pin_hash'] ?? null,
                        'pin_generated_at' => $order->metadata['assistance_case']['pin_generated_at'] ?? null,
                    ]);
                    $assistanceCase['pin'] = $pin;
                })(),
                MovipassOrderStatusEnum::DISPATCHED->slug() => $assistanceCase['dispatched_at'] = $timestamp,
                MovipassOrderStatusEnum::ON_SITE->slug() => $assistanceCase['arrived_at'] = $timestamp,
                MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug() => $assistanceCase['service_started_at'] = $timestamp,
                MovipassOrderStatusEnum::SERVICE_COMPLETED->slug() => (function () use (&$assistanceCase, $timestamp) {
                    $assistanceCase['completed_at'] = $timestamp;
                    $assistanceCase['resolved'] = true;
                    $assistanceCase['pin_hash'] = null;
                    $assistanceCase['pin_invalidated_at'] = $timestamp;
                })(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug() => (function () use (&$assistanceCase, $timestamp) {
                    $assistanceCase['completed_at'] = $timestamp;
                    $assistanceCase['resolved'] = false;
                    $assistanceCase['pin_hash'] = null;
                    $assistanceCase['pin_invalidated_at'] = $timestamp;
                })(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug() => (function () use (&$assistanceCase, $timestamp) {
                    $assistanceCase['cancelled_at'] = $timestamp;
                    $assistanceCase['pin_hash'] = null;
                    $assistanceCase['pin_invalidated_at'] = $timestamp;
                })(),
                default => null,
            };

            $order->metadata = [
                ...$metadata,
                'assistance_case' => $assistanceCase,
                'data' => [
                    ...($metadata['data'] ?? []),
                    'assistance_case' => $assistanceCase,
                ],
            ];
            $order->saveQuietly();

            match ($toStatus) {
                MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug() => $order->fulfill(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug() => $order->fulfillCancelled(),
                default => null,
            };
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Roadside assistance status transitioned to ' . $toStatus,
            'data' => $order->toArray(),
            'response' => $order->toArray(),
        ];
    }

    private function saveAssistanceCaseMetadata($order, array $metadata, array $assistanceCase): void
    {
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

    private function getFormattedTimestamp(): string
    {
        return Carbon::now()->setTimezone('America/Santo_Domingo')->format('d/m/Y h:i A');
    }
}
