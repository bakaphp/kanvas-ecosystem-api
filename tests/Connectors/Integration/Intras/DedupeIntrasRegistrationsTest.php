<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\DedupeIntrasRegistrationsAction;
use Kanvas\Connectors\Intras\Actions\PullRegistrationsFromIntrasAction;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Tests\TestCase;
use Tests\Traits\BuildsEventVersionFixtures;

/**
 * Registrations imported before the importer keyed on the SIPGO row id carry no
 * `intras_registration_id`; the first id-keyed run created a tracked twin of each.
 */
class DedupeIntrasRegistrationsTest extends TestCase
{
    use BuildsEventVersionFixtures;

    private static int $legacyId = 0;

    public function test_a_dry_run_counts_the_untracked_copies_and_deletes_nothing(): void
    {
        $version = $this->versionImportedTwice(people: 3);

        $result = $this->dedupe(apply: false);

        $this->assertGreaterThanOrEqual(3, $result['duplicates']);
        $this->assertSame(0, $result['removed']);
        $this->assertCount(6, $this->liveRegistrations($version));
    }

    public function test_applying_keeps_the_tracked_copy_and_recounts_attendees(): void
    {
        $version = $this->versionImportedTwice(people: 3);

        $this->dedupe(apply: true);
        $live = $this->liveRegistrations($version);

        $this->assertCount(3, $live);
        $this->assertTrue($live->every(fn (EventVersionParticipant $row) => $row->get(CustomFieldEnum::INTRAS_REGISTRATION_ID->value) !== null));
        $this->assertSame(3, (int) $version->fresh()->total_attendees);
    }

    /**
     * A courtesy seat and a paid seat for the same person are two SIPGO registrations, both
     * tracked. Neither is a duplicate, which is why this is not a unique index on (version, person).
     */
    public function test_two_tracked_registrations_for_one_person_are_both_kept(): void
    {
        $version = $this->createEventVersionWithParticipants(participantCount: 1);
        $original = $this->liveRegistrations($version)->first();
        $this->track($original);
        $this->track($this->copyOf($original));

        $this->dedupe(apply: true);

        $this->assertCount(2, $this->liveRegistrations($version));
    }

    public function test_an_untracked_registration_without_a_tracked_twin_is_kept(): void
    {
        $version = $this->createEventVersionWithParticipants(participantCount: 2);

        $this->dedupe(apply: true);

        $this->assertCount(2, $this->liveRegistrations($version));
    }

    /**
     * What makes the next run safe for a company still on the old rows: the importer claims the
     * existing registration instead of creating a second one.
     */
    public function test_the_importer_adopts_an_untracked_registration_instead_of_duplicating_it(): void
    {
        $version = $this->createEventVersionWithParticipants(participantCount: 1);
        $existing = $this->liveRegistrations($version)->first();
        $importer = $this->importer();
        $attributes = [
            'event_version_id' => $existing->event_version_id,
            'participant_id' => $existing->participant_id,
            'participant_type_id' => $existing->participant_type_id,
            'ticket_price' => 250,
            'discount' => 0,
            'invoice_date' => $existing->invoice_date,
            'metadata' => [],
        ];

        $adopted = $importer->resolve(880000000 + ++self::$legacyId, $attributes);
        $second = $importer->resolve(880000000 + ++self::$legacyId, $attributes);

        $this->assertSame($existing->getId(), $adopted->getId());
        $this->assertSame(250.0, (float) $adopted->fresh()->ticket_price);
        $this->assertNotSame($existing->getId(), $second->getId(), 'a second SIPGO registration is still its own row');
        $this->assertCount(2, $this->liveRegistrations($version));
    }

    private function versionImportedTwice(int $people): EventVersion
    {
        $version = $this->createEventVersionWithParticipants(participantCount: $people);

        foreach ($this->liveRegistrations($version) as $original) {
            $this->track($this->copyOf($original));
        }

        return $version;
    }

    private function copyOf(EventVersionParticipant $registration): EventVersionParticipant
    {
        return EventVersionParticipant::create($registration->only([
            'event_version_id',
            'participant_id',
            'participant_type_id',
            'ticket_price',
            'discount',
            'invoice_date',
        ]));
    }

    private function track(EventVersionParticipant $registration): void
    {
        $registration->set(CustomFieldEnum::INTRAS_REGISTRATION_ID->value, 880000000 + ++self::$legacyId);
    }

    /**
     * @return array{versions: int, duplicates: int, removed: int}
     */
    private function dedupe(bool $apply): array
    {
        return new DedupeIntrasRegistrationsAction(app(Apps::class), $this->company(), $apply)->execute();
    }

    private function liveRegistrations(EventVersion $version)
    {
        return EventVersionParticipant::query()->where('event_version_id', $version->getId())->orderBy('id')->get();
    }

    private function company(): Companies
    {
        /** @var Companies */
        return auth()->user()->getCurrentCompany();
    }

    private function importer(): object
    {
        return new class (app(Apps::class), $this->company(), auth()->user()) extends PullRegistrationsFromIntrasAction {
            public function resolve(int $legacyId, array $attributes): EventVersionParticipant
            {
                return $this->resolveRegistration($legacyId, $attributes);
            }
        };
    }
}
