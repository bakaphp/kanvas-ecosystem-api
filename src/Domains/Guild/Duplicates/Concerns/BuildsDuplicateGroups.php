<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Concerns;

use Illuminate\Support\Collection;

/**
 * Shared by FindOrganizationDuplicatesService and FindPeopleDuplicatesService — both group rows
 * into the same DTO shape (canonical_id, member_ids, reason, sample_name), just for a different
 * entity/DTO class, so the group-building and dedup mechanics live here once.
 */
trait BuildsDuplicateGroups
{
    /**
     * A record can match on more than one dimension (e.g. same name AND same email). Callers merge
     * dimensions in descending confidence order before calling this, so the first occurrence of a
     * given member set wins the tie.
     */
    private function dedupeGroups(array $groups): array
    {
        $deduped = [];
        foreach ($groups as $group) {
            $signature = implode('|', $group->member_ids);
            if (! isset($deduped[$signature])) {
                $deduped[$signature] = $group;
            }
        }

        return array_values($deduped);
    }

    /**
     * @param  class-string  $dtoClass
     */
    private function groupForRecord(string $dtoClass, int $recordId, array $matchIds, string $reason, string $sampleName): array
    {
        if (empty($matchIds)) {
            return [];
        }

        $memberIds = array_map('intval', array_merge([$recordId], $matchIds));
        sort($memberIds);

        return [new $dtoClass(
            canonical_id: $memberIds[0],
            member_ids: $memberIds,
            reason: $reason,
            sample_name: $sampleName,
        )];
    }

    /**
     * @param  class-string  $dtoClass
     * @param  Collection<int, object>  $rows  each row has a comma-separated `ids` and a `sample_name`
     */
    private function mapRowsToGroups(string $dtoClass, Collection $rows, string $reason): array
    {
        $out = [];
        foreach ($rows as $row) {
            $memberIds = array_map('intval', explode(',', (string) $row->ids));
            sort($memberIds);
            if (count($memberIds) < 2) {
                continue;
            }
            $out[] = new $dtoClass(
                canonical_id: $memberIds[0],
                member_ids: $memberIds,
                reason: $reason,
                sample_name: (string) ($row->sample_name ?? ''),
            );
        }

        return $out;
    }
}
