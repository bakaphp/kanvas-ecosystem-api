<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;

/**
 * Removes the second copy of every registration imported before registrations were keyed on the
 * SIPGO row id.
 *
 * Those early rows carry no `intras_registration_id`, so the first run of the id-keyed importer
 * could not see them and created a claimed twin of each. The duplicate to drop is the
 * **unclaimed** one: it is the copy the importer no longer tracks, and keeping it instead would
 * make the next run create the row all over again.
 *
 * A row is only dropped when a claimed row exists for the same version, person and type. Two
 * genuine SIPGO registrations for one person are both claimed and are never touched, which is
 * why this cannot be a unique index on (version, participant).
 *
 * Deletes run without model events: the observer would fire the `participant-removed` workflow,
 * which can notify the participant. `total_attendees` is recounted instead of decremented, because
 * the import that created the twins also incremented it once per twin.
 */
class DedupeIntrasRegistrationsAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected bool $apply = false,
    ) {
    }

    /**
     * @return array{versions: int, duplicates: int, removed: int}
     */
    public function execute(): array
    {
        $claimed = array_fill_keys(array_values(PullRegistrationsFromIntrasAction::loadIntrasMap(
            null,
            EventVersionParticipant::class,
            CustomFieldEnum::INTRAS_REGISTRATION_ID->value
        )), true);

        $versionIds = EventVersion::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->pluck('id');

        $affectedVersions = [];
        $duplicates = 0;

        foreach ($versionIds->chunk(500) as $chunk) {
            $registrations = EventVersionParticipant::query()
                ->whereIn('event_version_id', $chunk->all())
                ->orderBy('id')
                ->get(['id', 'event_version_id', 'participant_id', 'participant_type_id'])
                ->groupBy(fn (EventVersionParticipant $row) => $row->event_version_id . ':' . $row->participant_id . ':' . $row->participant_type_id);

            foreach ($registrations as $group) {
                [$tracked, $untracked] = $group->partition(fn (EventVersionParticipant $row) => isset($claimed[$row->getId()]));

                if ($tracked->isEmpty() || $untracked->isEmpty()) {
                    continue;
                }

                $duplicates += $untracked->count();
                $affectedVersions[$group->first()->event_version_id] = true;

                if ($this->apply) {
                    EventVersionParticipant::withoutEvents(
                        fn () => $untracked->each(fn (EventVersionParticipant $row) => $row->delete())
                    );
                }
            }
        }

        if ($this->apply) {
            $this->recountAttendees(array_keys($affectedVersions));
        }

        return [
            'versions' => count($affectedVersions),
            'duplicates' => $duplicates,
            'removed' => $this->apply ? $duplicates : 0,
        ];
    }

    /**
     * @param list<int> $versionIds
     */
    protected function recountAttendees(array $versionIds): void
    {
        EventVersion::query()->whereIn('id', $versionIds)->each(function (EventVersion $version): void {
            $version->total_attendees = EventVersionParticipant::query()
                ->where('event_version_id', $version->getId())
                ->count();
            $version->saveQuietly();
        });
    }
}
