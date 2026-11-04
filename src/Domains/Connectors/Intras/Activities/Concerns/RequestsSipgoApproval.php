<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Activities\Concerns;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Kanvas\Approvals\Concerns\OpensApprovalWithSupersede;
use Kanvas\Exceptions\ValidationException;

/**
 * One Control Center save fires several events (UPDATED plus one CREATE_CUSTOM_FIELD per field), so
 * several workers reach this at once for the same record. The lock serialises the check-then-open,
 * and the identical-pending check turns the followers into no-ops.
 */
trait RequestsSipgoApproval
{
    use OpensApprovalWithSupersede;

    public static function lockKey(Model $entity): string
    {
        return 'sipgo-approval:' . class_basename($entity) . ':' . $entity->getKey();
    }

    /**
     * @param callable(): (list<array>|null) $diff items, [] when SIPGO matches, null when the linked row is gone
     * @param array<string, mixed> $payload merged with ['items' => ...]
     */
    protected function requestSipgoApproval(
        Model $entity,
        string $approvalType,
        callable $diff,
        array $payload,
        array $params,
        string $missingReason
    ): array {
        try {
            return Cache::lock(self::lockKey($entity), 30)->block(5, function () use ($entity, $approvalType, $diff, $payload, $params, $missingReason): array {
                try {
                    $items = $diff();
                } catch (ValidationException $e) {
                    return $this->failWorkflow(['requested' => false, 'reason' => $e->getMessage()]);
                }

                if ($items === null) {
                    return $this->failWorkflow(['requested' => false, 'reason' => $missingReason]);
                }

                if ($items === []) {
                    return ['requested' => false, 'reason' => 'SIPGO already matches'];
                }

                $pendingItems = $entity->pendingApproval($approvalType)?->payload['items'] ?? null;

                // Key-sorted, then strict: the JSON column reorders keys, and `==` would compare numeric
                // strings as numbers — "0112345678" == "112345678" — dropping a real edit as already pending.
                if ($pendingItems !== null && Arr::sortRecursive($pendingItems) === Arr::sortRecursive($items)) {
                    return ['requested' => false, 'reason' => 'already pending'];
                }

                return $this->openOrSupersede(
                    $entity,
                    $approvalType,
                    [...$payload, 'items' => $items],
                    ['auto_reject_stale_pending' => true, ...$params],
                );
            });
        } catch (LockTimeoutException) {
            return ['requested' => false, 'reason' => 'another request for this record is being opened'];
        }
    }
}
