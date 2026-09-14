<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Actions\AssignMechanicToOrderAction;
use Kanvas\Connectors\Movipass\Actions\AttachRoadsideAssistancePhotosAction;
use Kanvas\Connectors\Movipass\Actions\CancelMechanicAssignmentAction;
use Kanvas\Connectors\Movipass\Actions\CheckMechanicArrivalAction;
use Kanvas\Connectors\Movipass\Actions\GenerateRoadsideAssistancePinAction;
use Kanvas\Connectors\Movipass\Actions\NotifyAvailableMechanicsAction;
use Kanvas\Connectors\Movipass\Actions\PrepareRoadsideAssistanceCaseAction;
use Kanvas\Connectors\Movipass\Actions\SendRoadsideChatMessagePushAction;
use Kanvas\Connectors\Movipass\Actions\ValidateRoadsideAssistancePinAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
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
                    $result = $this->handleCreated($order, $app);
                } elseif ($eventName === WorkflowEnum::STATUS_TRANSITION->value) {
                    $result = $this->handleStatusTransition($order, $params['to_status'] ?? null);
                } elseif ($eventName === WorkflowEnum::UPDATED->value) {
                    $result = $this->handleUpdated($order, $app);
                } else {
                    $result = [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'No roadside assistance processing required for this event',
                    ];
                }

                return [...$result, ...$this->pushPendingRoadsideChat($order)];
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

                RefreshActiveAssistanceEvent::dispatch($order, $mechanicUserId);

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

        if (($assistanceCase['cancel_order'] ?? false) === true) {
            $terminalStatuses = [
                MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            ];
            if (in_array($currentStatusSlug, $terminalStatuses, true)) {
                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Cancel ignored: order is already in a terminal status',
                ];
            }

            $user = Users::getById((int) $order->users_id);
            $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);

            unset($assistanceCase['cancel_order']);
            $assistanceCase['cancelled_at'] = Carbon::now()->toISOString();
            $assistanceCase['status'] = MovipassOrderStatusEnum::SERVICE_CANCELLED->slug();
            $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();
            $assistanceCase['pin_hash'] = null;
            $assistanceCase['pin_invalidated_at'] = Carbon::now()->toISOString();

            $order->metadata = [
                ...$metadata,
                'assistance_case' => $assistanceCase,
                'data' => [
                    ...($metadata['data'] ?? []),
                    'assistance_case' => $assistanceCase,
                ],
            ];
            $order->saveQuietly();

            $order->transitionToStatus($user, MovipassOrderStatusEnum::SERVICE_CANCELLED->slug());
            $order->fulfillCancelled();

            $broadcastId = $mechanicId > 0 ? $mechanicId : (int) $order->users_id;
            RefreshActiveAssistanceEvent::dispatch($order, $broadcastId);

            $user->notify(new RoadsideAssistanceStatusNotification(
                $order,
                'Service cancelled',
                'The roadside assistance service has been cancelled.',
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            ));

            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'Order cancelled and transitioned to service_cancelled',
                'data' => $order->toArray(),
                'response' => $order->toArray(),
            ];
        }

        if (($assistanceCase['complete_order'] ?? false) === true || ($assistanceCase['complete_order_not_resolved'] ?? false) === true) {
            $terminalStatuses = [
                MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            ];
            if (in_array($currentStatusSlug, $terminalStatuses, true)) {
                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Completion ignored: order is already in a terminal status',
                ];
            }

            $isResolved = ($assistanceCase['complete_order'] ?? false) === true;
            $user = Users::getById((int) $order->users_id);
            $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);

            unset($assistanceCase['complete_order'], $assistanceCase['complete_order_not_resolved']);
            $assistanceCase['mechanic_completion_requested'] = true;
            $assistanceCase['mechanic_completion_resolved'] = $isResolved;
            $assistanceCase['mechanic_completion_requested_at'] = Carbon::now()->toISOString();
            $assistanceCase['pending_user_confirmation'] = true;
            $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();

            $this->saveAssistanceCaseMetadata($order, $metadata, $assistanceCase);

            $broadcastId = $mechanicId > 0 ? $mechanicId : (int) $order->users_id;
            RefreshActiveAssistanceEvent::dispatch($order, $broadcastId);

            $user->notify(new RoadsideAssistanceStatusNotification(
                $order,
                'Confirm service completion',
                'The mechanic marked the service as complete. Please confirm to close the case.',
                $currentStatusSlug ?? MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug(),
            ));

            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'Mechanic completion request recorded, awaiting user confirmation',
                'data' => $order->toArray(),
                'response' => $order->toArray(),
            ];
        }

        if (($assistanceCase['user_confirm_completion'] ?? false) === true) {
            $terminalStatuses = [
                MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            ];
            if (in_array($currentStatusSlug, $terminalStatuses, true)) {
                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'User confirmation ignored: order is already in a terminal status',
                ];
            }

            if (($assistanceCase['mechanic_completion_requested'] ?? false) !== true) {
                return [
                    'order' => $order->getId(),
                    'status' => 'error',
                    'message' => 'Cannot confirm completion: the mechanic has not marked the service as complete',
                ];
            }

            try {
                $rating = $this->normalizeRating($assistanceCase['user_rating'] ?? null);
            } catch (ValidationException $e) {
                return [
                    'order' => $order->getId(),
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
            $feedback = $this->normalizeFeedback($assistanceCase['user_feedback'] ?? null);

            $isResolved = (bool) ($assistanceCase['mechanic_completion_resolved'] ?? true);
            $targetStatus = $isResolved
                ? MovipassOrderStatusEnum::SERVICE_COMPLETED->slug()
                : MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug();

            $user = Users::getById((int) $order->users_id);
            $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);

            unset($assistanceCase['user_confirm_completion'], $assistanceCase['pending_user_confirmation']);
            $assistanceCase['user_confirmed_completion'] = true;
            $assistanceCase['user_confirmed_at'] = Carbon::now()->toISOString();
            if ($rating !== null) {
                $assistanceCase['user_rating'] = $rating;
            } else {
                unset($assistanceCase['user_rating']);
            }
            if ($feedback !== null) {
                $assistanceCase['user_feedback'] = $feedback;
            } else {
                unset($assistanceCase['user_feedback']);
            }
            $assistanceCase['completed_at'] = Carbon::now()->toISOString();
            $assistanceCase['resolved'] = $isResolved;
            $assistanceCase['status'] = $targetStatus;
            $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();
            $assistanceCase['pin_hash'] = null;
            $assistanceCase['pin_invalidated_at'] = Carbon::now()->toISOString();

            $this->saveAssistanceCaseMetadata($order, $metadata, $assistanceCase);

            $order->transitionToStatus($user, $targetStatus);
            $order->fulfill();

            $broadcastId = $mechanicId > 0 ? $mechanicId : (int) $order->users_id;
            RefreshActiveAssistanceEvent::dispatch($order, $broadcastId);

            [$notifTitle, $notifMessage] = $isResolved
                ? ['Service completed', 'The roadside assistance service has been completed successfully.']
                : ['Service completed', 'The roadside assistance service has ended without resolution.'];

            $notification = new RoadsideAssistanceStatusNotification($order, $notifTitle, $notifMessage, $targetStatus);
            $user->notify($notification);
            if ($mechanicId > 0) {
                Users::getById($mechanicId)->notify($notification);
            }

            return [
                'order' => $order->getId(),
                'status' => 'success',
                'message' => 'Order transitioned to ' . $targetStatus . ' after user confirmation',
                'data' => $order->toArray(),
                'response' => $order->toArray(),
            ];
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
        $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);
        if ($mechanicId > 0) {
            RefreshActiveAssistanceEvent::dispatch($order, $mechanicId);
        }

        $assistanceCase['pin_validated_at'] = Carbon::now()->toISOString();
        $assistanceCase['status'] = MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug();
        $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();
        unset($assistanceCase['pin_attempt'], $assistanceCase['pin_validation_error']);
        $this->saveAssistanceCaseMetadata($order, $metadata, $assistanceCase);

        $orderUser = Users::getById((int) $order->users_id);
        $order->transitionToStatus(
            $orderUser,
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug(),
        );

        $orderUser->notify(new RoadsideAssistanceStatusNotification(
            $order,
            'Service started',
            'The mechanic has started the roadside assistance service.',
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug(),
        ));

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

            $refreshStatuses = [
                MovipassOrderStatusEnum::ON_SITE->slug(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            ];

            if (in_array($toStatus, $refreshStatuses, true)) {
                $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);
                $broadcastMechanicId = $mechanicId > 0 ? $mechanicId : (int) $order->users_id;
                RefreshActiveAssistanceEvent::dispatch($order, $broadcastMechanicId);

                $orderUser = Users::getById((int) $order->users_id);
                $mechanic = $mechanicId > 0 ? Users::getById($mechanicId) : null;

                [$title, $message, $event] = match ($toStatus) {
                    MovipassOrderStatusEnum::SERVICE_COMPLETED->slug() => [
                        'Service completed',
                        'The roadside assistance service has been completed successfully.',
                        MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                    ],
                    MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug() => [
                        'Service completed',
                        'The roadside assistance service has ended without resolution.',
                        MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
                    ],
                    MovipassOrderStatusEnum::SERVICE_CANCELLED->slug() => [
                        'Service cancelled',
                        'The roadside assistance service has been cancelled.',
                        MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
                    ],
                    default => ['Order updated', 'Your order has been updated.', $toStatus],
                };

                $notification = new RoadsideAssistanceStatusNotification($order, $title, $message, $event);
                $orderUser->notify($notification);
                if ($mechanic instanceof Users) {
                    $mechanic->notify($notification);
                }
            }
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Roadside assistance status transitioned to ' . $toStatus,
            'data' => $order->toArray(),
            'response' => $order->toArray(),
        ];
    }

    /**
     * The roadside chat is a Social Message in a dm-{customer}-{mechanic} channel; it never lands
     * on the order. Since this sync fires on every roadside order change, resolve that channel from
     * the order's participants, and push the latest chat message to the counterparty if it hasn't
     * been pushed yet. The last-pushed id is tracked in the assistance_case metadata for idempotency.
     */
    private function pushPendingRoadsideChat($order): array
    {
        $metadata = $order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        $customerId = (int) $order->users_id;
        $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);

        if ($customerId <= 0 || $mechanicId <= 0) {
            return ['chat_push' => 'skipped', 'chat_push_reason' => 'no mechanic assigned'];
        }

        $channel = Channel::query()
            ->where('apps_id', $order->apps_id)
            ->whereIn('slug', [
                'dm-' . $customerId . '-' . $mechanicId,
                'dm-' . $mechanicId . '-' . $customerId,
            ])
            ->where('is_deleted', 0)
            ->first();

        if ($channel === null) {
            return ['chat_push' => 'skipped', 'chat_push_reason' => 'chat channel not found'];
        }

        $message = $channel->messages()
            ->whereHas('messageType', fn ($query) => $query->where('verb', SendRoadsideChatMessagePushAction::ROADSIDE_CHAT_VERB))
            ->orderByDesc('messages.id')
            ->first();

        if ($message === null) {
            return ['chat_push' => 'skipped', 'chat_push_reason' => 'no chat message in channel'];
        }

        $lastPushed = (int) ($assistanceCase['last_pushed_chat_message_id'] ?? 0);

        if ((int) $message->id <= $lastPushed) {
            return ['chat_push' => 'skipped', 'chat_push_reason' => 'already pushed'];
        }

        $notified = new SendRoadsideChatMessagePushAction($channel, $message)->execute();

        if ($notified === 0) {
            return $this->failWorkflow([
                'chat_push' => 'failed',
                'chat_push_reason' => 'resolved a new chat message but notified 0 recipients',
                'chat_push_message_id' => (int) $message->id,
            ]);
        }

        $assistanceCase['last_pushed_chat_message_id'] = (int) $message->id;
        $this->saveAssistanceCaseMetadata($order, $metadata, $assistanceCase);

        return [
            'chat_push' => 'sent',
            'chat_push_message_id' => (int) $message->id,
            'chat_push_recipients' => $notified,
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

    private function normalizeRating(mixed $rating): ?int
    {
        if ($rating === null || $rating === '') {
            return null;
        }

        if (! is_numeric($rating)) {
            throw new ValidationException('User rating must be a number between 1 and 5');
        }

        $value = (int) $rating;
        if ($value < 1 || $value > 5) {
            throw new ValidationException('User rating must be between 1 and 5');
        }

        return $value;
    }

    private function normalizeFeedback(mixed $feedback): ?string
    {
        if (! is_string($feedback)) {
            return null;
        }

        return Str::trimToNull($feedback);
    }
}
