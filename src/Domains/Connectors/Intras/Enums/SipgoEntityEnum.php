<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Enums;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * The three SIPGO rows Kanvas writes back to. `custom_fields.name` repeats across modules, so each
 * kind carries the module its custom fields live under (read from production, 2026-11-04).
 */
enum SipgoEntityEnum: string
{
    case PARTICIPANT = 'participant';
    case FACILITATOR = 'facilitator';
    case COMPANY = 'company';

    public function table(): string
    {
        return match ($this) {
            self::PARTICIPANT => 'participants',
            self::FACILITATOR => 'facilitators',
            self::COMPANY => 'companies',
        };
    }

    public function customFieldsTable(): string
    {
        return $this->table() . '_custom_fields';
    }

    public function foreignKey(): string
    {
        return $this->table() . '_id';
    }

    public function customFieldsModuleId(): int
    {
        return match ($this) {
            self::PARTICIPANT => 4,
            self::FACILITATOR => 3,
            self::COMPANY => 1,
        };
    }

    public function linkField(): CustomFieldEnum
    {
        return match ($this) {
            self::PARTICIPANT => CustomFieldEnum::INTRAS_PARTICIPANT_ID,
            self::FACILITATOR => CustomFieldEnum::INTRAS_FACILITATOR_ID,
            self::COMPANY => CustomFieldEnum::INTRAS_COMPANY_ID,
        };
    }

    /**
     * The SIPGO id a People / Organization is linked to for this kind, or null when unlinked.
     */
    public function legacyIdOf(Model $entity): ?int
    {
        $id = (int) $entity->get($this->linkField()->value);

        return $id > 0 ? $id : null;
    }

    /**
     * `legacy_id` null means "create this row"; the push fills it in.
     *
     * @return array{kind: string, legacy_id: ?int, changes: array}
     */
    public function item(?int $legacyId, array $changes): array
    {
        return ['kind' => $this->value, 'legacy_id' => $legacyId, 'changes' => $changes];
    }

    /**
     * Custom-field values for a batch of rows, keyed legacy id => [name => value]. Resolved by
     * `custom_fields.name` because the ids are install-specific; blanks are dropped.
     *
     * @param list<int> $legacyIds
     * @param list<string> $names
     *
     * @return array<int, array<string, string>>
     */
    public function loadCustomFields(Client $client, array $legacyIds, array $names): array
    {
        if ($legacyIds === []) {
            return [];
        }

        $rows = $client->table($this->customFieldsTable() . ' as v')
            ->join('custom_fields as cf', 'cf.id', '=', 'v.custom_fields_id')
            ->whereIn('v.' . $this->foreignKey(), $legacyIds)
            ->whereIn('cf.name', $names)
            ->whereNotNull('v.value')
            ->where('v.value', '!=', '')
            ->select('v.' . $this->foreignKey() . ' as legacy_id', 'cf.name', 'v.value')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->legacy_id][(string) $row->name] = (string) $row->value;
        }

        return $map;
    }

    /**
     * @return list<self>
     */
    public static function forEntity(Model $entity): array
    {
        return match (true) {
            $entity instanceof People => [self::PARTICIPANT, self::FACILITATOR],
            $entity instanceof Organization => [self::COMPANY],
            default => [],
        };
    }
}
