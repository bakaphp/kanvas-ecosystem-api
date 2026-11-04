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

            $legacyId = (int) ($item['legacy_id'] ?? 0);

            if ($legacyId <= 0 || $kind->legacyIdOf($this->entity) !== $legacyId) {
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

        return $client->getConnection()->transaction(
            fn () => array_map(fn (WriteSipgoChangeSetAction $writer) => $writer->execute(), $writers),
        );
    }
}
