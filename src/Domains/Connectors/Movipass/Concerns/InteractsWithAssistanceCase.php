<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Concerns;

use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * The assistance case is stored twice on the order metadata — at the root and under `data` — because
 * different clients read it from different places. Anything mutating a case has to keep both copies
 * in step, so the read/write pair lives here instead of in each caller.
 */
trait InteractsWithAssistanceCase
{
    protected function assistanceCaseFrom(Order $order): array
    {
        $metadata = $order->metadata ?? [];

        return $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);
    }

    protected function saveAssistanceCase(Order $order, array $assistanceCase): void
    {
        $metadata = $order->metadata ?? [];

        $order->metadata = [
            ...$metadata,
            'assistance_case' => $assistanceCase,
            'data' => [
                ...(is_array($metadata['data'] ?? null) ? $metadata['data'] : []),
                'assistance_case' => $assistanceCase,
            ],
        ];
        $order->saveQuietly();
    }

    /**
     * An action that pushes its own, more specific message before transitioning marks the target
     * status here; the STATUS_TRANSITION run that the transition fires then skips its generic push
     * for that status instead of sending the recipient a second one.
     */
    protected function markStatusNotified(array $assistanceCase, string $statusSlug): array
    {
        $assistanceCase['status_notified'] = $statusSlug;

        return $assistanceCase;
    }

    /**
     * The marker is cleared on read, so a later transition back into the same status (an operator
     * moving the order by hand) still notifies.
     */
    protected function consumeStatusNotified(array &$assistanceCase, string $statusSlug): bool
    {
        $notified = ($assistanceCase['status_notified'] ?? null) === $statusSlug;
        unset($assistanceCase['status_notified']);

        return $notified;
    }

    protected function isTerminalAssistanceStatus(?string $statusSlug): bool
    {
        return in_array($statusSlug, [
            MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
            MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
            MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
            MovipassOrderStatusEnum::SERVICE_NOT_AUTHORIZED->slug(),
        ], true);
    }

    /**
     * The state change is committed before we notify, so a push/mail outage must not fail the
     * caller or stop the other recipient from hearing about it.
     */
    protected function notifySafely(?Users $user, RoadsideAssistanceStatusNotification $notification): void
    {
        if (! $user instanceof Users) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function resolveCaseUser(mixed $userId): ?Users
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return null;
        }

        try {
            return Users::getById($userId);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
