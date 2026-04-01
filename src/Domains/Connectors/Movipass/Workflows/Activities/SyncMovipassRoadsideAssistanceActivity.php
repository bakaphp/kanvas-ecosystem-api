<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Actions\AcceptOrderAssignmentAction;
use Kanvas\Connectors\Movipass\Actions\AttachRoadsideAssistancePhotosAction;
use Kanvas\Connectors\Movipass\Actions\GenerateRoadsideAssistancePinAction;
use Kanvas\Connectors\Movipass\Actions\NotifyAvailableMechanicsAction;
use Kanvas\Connectors\Movipass\Actions\PrepareRoadsideAssistanceCaseAction;
use Kanvas\Connectors\Movipass\Actions\ValidateRoadsideAssistancePinAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Users\Models\Users;
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
        $metadata = new PrepareRoadsideAssistanceCaseAction()->execute(
            $order->metadata ?? [],
            $order->user,
        );

        $order->metadata = $metadata;
        $order->saveQuietly();

        $photos = $metadata['assistance_case']['photos'] ?? [];
        if ($photos !== []) {
            $attachAction = new AttachRoadsideAssistancePhotosAction();
            $attachedPhotos = $attachAction->execute($order, $photos, $app);
            $metadata['assistance_case']['photos'] = $attachedPhotos;
            $metadata['data']['assistance_case']['photos'] = $attachedPhotos;
            $order->metadata = $metadata;
            $order->saveQuietly();
        }

        $mechanic = $metadata['assistance_case']['mechanic'] ?? null;
        if (empty($mechanic['user_id'])) {
            new NotifyAvailableMechanicsAction($order, $app)->execute();
        }

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

        // 1. Mechanic acceptance — AWAITING_OPERATOR + mechanic_accept flag
        if ($currentStatusSlug === MovipassOrderStatusEnum::AWAITING_OPERATOR->value) {
            $mechanicAccept = $assistanceCase['mechanic_accept'] ?? null;
            $mechanicUserId = is_array($mechanicAccept) ? ($mechanicAccept['user_id'] ?? null) : null;

            if ($mechanicUserId !== null) {
                unset($assistanceCase['mechanic_accept']);
                $this->saveAssistanceCaseMetadata($order, $metadata, $assistanceCase);

                try {
                    $mechanic = Users::getById((int) $mechanicUserId, $app);
                    new AcceptOrderAssignmentAction(
                        order: $order,
                        mechanic: $mechanic,
                    )->execute();
                } catch (ValidationException $e) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'error',
                        'message' => 'Order acceptance failed: ' . $e->getMessage(),
                    ];
                }

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Order accepted, transitioned to provider_assigned',
                ];
            }
        }

        // 2. PIN validation — PROVIDER_ASSIGNED + pin_attempt
        if ($currentStatusSlug === MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value) {
            $pinAttempt = $assistanceCase['pin_attempt'] ?? null;

            if ($pinAttempt !== null && trim((string) $pinAttempt) !== '') {
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

                $assistanceCase['pin_validated_at'] = Carbon::now()->toISOString();
                unset($assistanceCase['pin_attempt'], $assistanceCase['pin_validation_error']);
                $this->saveAssistanceCaseMetadata($order, $metadata, $assistanceCase);

                $order->transitionToStatus(
                    auth()->user(),
                    MovipassOrderStatusEnum::DISPATCHED->value,
                );

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'PIN validated, order transitioned to dispatched',
                ];
            }
        }

        // 3. Status metadata sync — idempotent, runs on every UPDATED for any status
        if ($assistanceCase !== []) {
            $timestamp = $this->getFormattedTimestamp();
            $needsFulfill = false;
            $needsCancelFulfill = false;

            match ($currentStatusSlug) {
                MovipassOrderStatusEnum::AWAITING_OPERATOR->value => $assistanceCase['awaiting_operator_at'] ??= $timestamp,
                MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value => (function () use ($order, &$assistanceCase, $timestamp) {
                    if (isset($assistanceCase['provider_assigned_at'])) {
                        return;
                    }
                    $assistanceCase['provider_assigned_at'] = $timestamp;
                    $pin = new GenerateRoadsideAssistancePinAction($order)->execute();
                    $order->refresh();
                    $assistanceCase = array_merge($assistanceCase, [
                        'pin_hash' => $order->metadata['assistance_case']['pin_hash'] ?? null,
                        'pin_generated_at' => $order->metadata['assistance_case']['pin_generated_at'] ?? null,
                    ]);
                    $assistanceCase['pin'] = $pin;
                })(),
                MovipassOrderStatusEnum::DISPATCHED->value => $assistanceCase['dispatched_at'] ??= $timestamp,
                MovipassOrderStatusEnum::ON_SITE->value => $assistanceCase['arrived_at'] ??= $timestamp,
                MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value => $assistanceCase['service_started_at'] ??= $timestamp,
                MovipassOrderStatusEnum::SERVICE_COMPLETED->value => (function () use (&$assistanceCase, $timestamp, &$needsFulfill) {
                    if (isset($assistanceCase['completed_at'])) {
                        return;
                    }
                    $assistanceCase['completed_at'] = $timestamp;
                    $assistanceCase['resolved'] = true;
                    $assistanceCase['pin_hash'] = null;
                    $assistanceCase['pin_invalidated_at'] = $timestamp;
                    $needsFulfill = true;
                })(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value => (function () use (&$assistanceCase, $timestamp, &$needsFulfill) {
                    if (isset($assistanceCase['completed_at'])) {
                        return;
                    }
                    $assistanceCase['completed_at'] = $timestamp;
                    $assistanceCase['resolved'] = false;
                    $assistanceCase['pin_hash'] = null;
                    $assistanceCase['pin_invalidated_at'] = $timestamp;
                    $needsFulfill = true;
                })(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->value => (function () use (&$assistanceCase, $timestamp, &$needsCancelFulfill) {
                    if (isset($assistanceCase['cancelled_at'])) {
                        return;
                    }
                    $assistanceCase['cancelled_at'] = $timestamp;
                    $assistanceCase['pin_hash'] = null;
                    $assistanceCase['pin_invalidated_at'] = $timestamp;
                    $needsCancelFulfill = true;
                })(),
                default => null,
            };

            $this->saveAssistanceCaseMetadata($order, $metadata, $assistanceCase);

            if ($needsFulfill) {
                $order->fulfill();
            }

            if ($needsCancelFulfill) {
                $order->fulfillCancelled();
            }
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Roadside assistance order updated',
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
            $timestamp = $this->getFormattedTimestamp();

            $assistanceCase['status'] = $toStatus;
            $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();

            match ($toStatus) {
                MovipassOrderStatusEnum::AWAITING_OPERATOR->value => $assistanceCase['awaiting_operator_at'] = $timestamp,
                MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value => (function () use ($order, &$assistanceCase, $timestamp) {
                    $assistanceCase['provider_assigned_at'] = $timestamp;
                    $pin = new GenerateRoadsideAssistancePinAction($order)->execute();
                    $order->refresh();
                    $assistanceCase = array_merge($assistanceCase, [
                        'pin_hash' => $order->metadata['assistance_case']['pin_hash'] ?? null,
                        'pin_generated_at' => $order->metadata['assistance_case']['pin_generated_at'] ?? null,
                    ]);
                    $assistanceCase['pin'] = $pin;
                })(),
                MovipassOrderStatusEnum::DISPATCHED->value => $assistanceCase['dispatched_at'] = $timestamp,
                MovipassOrderStatusEnum::ON_SITE->value => $assistanceCase['arrived_at'] = $timestamp,
                MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->value => $assistanceCase['service_started_at'] = $timestamp,
                MovipassOrderStatusEnum::SERVICE_COMPLETED->value => (function () use (&$assistanceCase, $timestamp) {
                    $assistanceCase['completed_at'] = $timestamp;
                    $assistanceCase['resolved'] = true;
                    $assistanceCase['pin_hash'] = null;
                    $assistanceCase['pin_invalidated_at'] = $timestamp;
                })(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value => (function () use (&$assistanceCase, $timestamp) {
                    $assistanceCase['completed_at'] = $timestamp;
                    $assistanceCase['resolved'] = false;
                    $assistanceCase['pin_hash'] = null;
                    $assistanceCase['pin_invalidated_at'] = $timestamp;
                })(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->value => (function () use (&$assistanceCase, $timestamp) {
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
                MovipassOrderStatusEnum::SERVICE_COMPLETED->value,
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->value => $order->fulfill(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->value => $order->fulfillCancelled(),
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
