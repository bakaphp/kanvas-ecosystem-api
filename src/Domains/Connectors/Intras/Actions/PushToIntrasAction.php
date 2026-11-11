<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\SipgoEntityEnum;
use Kanvas\Exceptions\ValidationException;

/**
 * Writes an approved change set (the Diff*WithIntrasAction items) to SIPGO — the snapshot the
 * approver saw, not the record as it is now, so a SIPGO pull landing between request and approval
 * cannot change what gets written. A People may carry a participant and a facilitator item; all
 * items go in one transaction so a refused item leaves nothing half-written.
 */
class PushToIntrasAction
{
    /**
     * @param list<array<string, mixed>> $items as stored in the approval payload
     */
    public function __construct(
        protected Model $entity,
        protected array $items,
    ) {
    }

    /**
     * @return list<array{kind: string, legacy_id: int, row: list<string>, custom_fields: list<string>}>
     */
    public function execute(): array
    {
        $client = new Client($this->entity->app);
        $allowed = SipgoEntityEnum::forEntity($this->entity);
        $writers = [];

        foreach ($this->items as $item) {
            $kind = SipgoEntityEnum::tryFrom((string) ($item['kind'] ?? ''));

            if ($kind === null || ! in_array($kind, $allowed, true)) {
                throw new ValidationException(sprintf(
                    '%s %d change set cannot target SIPGO kind "%s".',
                    class_basename($this->entity),
                    $this->entity->getKey(),
                    $item['kind'] ?? '',
                ));
            }

            $legacyId = isset($item['legacy_id']) ? (int) $item['legacy_id'] : null;
            $linkedId = $kind->legacyIdOf($this->entity);

            if ($legacyId === null && $linkedId !== null) {
                throw new ValidationException(sprintf(
                    '%s %d is already linked to SIPGO %s %d; the create request is stale.',
                    class_basename($this->entity),
                    $this->entity->getKey(),
                    $kind->value,
                    $linkedId,
                ));
            }

            if ($legacyId !== null && ($legacyId <= 0 || $linkedId !== $legacyId)) {
                throw new ValidationException(sprintf(
                    '%s %d is not linked to SIPGO %s %d.',
                    class_basename($this->entity),
                    $this->entity->getKey(),
                    $kind->value,
                    $legacyId,
                ));
            }

            $writers[] = new WriteSipgoChangeSetAction(
                $client,
                $kind,
                $legacyId,
                (array) ($item['changes'] ?? []),
            );
        }

        $results = $client->getConnection()->transaction(
            fn () => array_map(fn (WriteSipgoChangeSetAction $writer) => $writer->execute(), $writers),
        );

        // Workflows paused: the stamp is bookkeeping, and the sync rules would otherwise fire on it.
        $this->entity->withoutWorkflows(function () use ($results): void {
            foreach ($results as $result) {
                $kind = SipgoEntityEnum::from($result['kind']);

                if ($kind->legacyIdOf($this->entity) === null) {
                    $this->entity->set($kind->linkField()->value, $result['legacy_id']);
                }
            }
        });

        return $results;
    }
}
