<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Repositories;

use Baka\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Reports\DataTransferObject\InscriptionTrack;

class InscriptionTrackRepository
{
    /**
     * Count registrations per ParticipantType for a given EventVersion.
     *
     * @param  list<string>  $excludeTypes Slugs to hide from the chart.
     * @return Collection<int, InscriptionTrack>
     */
    public static function forEventVersion(
        EventVersion $eventVersion,
        array $excludeTypes = [],
    ): Collection {
        $rows = DB::connection('event')
            ->table('event_version_participants')
            ->leftJoin('participant_types', 'event_version_participants.participant_type_id', '=', 'participant_types.id')
            ->where('event_version_participants.event_version_id', $eventVersion->getId())
            ->where('event_version_participants.is_deleted', 0)
            ->selectRaw('COALESCE(participant_types.name, ?) as type_name, COUNT(*) as cnt', ['Unassigned'])
            ->groupBy('type_name')
            ->orderByDesc('cnt')
            ->get();

        return $rows
            ->map(fn ($row) => new InscriptionTrack(
                type: (string) $row->type_name,
                slug: Str::slug((string) $row->type_name),
                count: (int) $row->cnt,
            ))
            ->reject(fn (InscriptionTrack $track) => in_array($track->slug, $excludeTypes, true))
            ->values();
    }
}
