<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras\Concerns;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Support\Setup;

/**
 * One SIPGO edition with its event, dates, companies, participants and inscriptions in the scratch
 * schema, plus the Kanvas side a pull needs to land it (event catalog, inscription type). Shared by
 * the on-demand sync tests.
 */
trait SeedsSipgoEventGraph
{
    use UsesSipgoScratchSchema;

    protected const int AGENCY_ID = 1;
    protected const int INSCRIPTION_TYPE_ID = 5;

    protected function prepareKanvasForSipgoEvents(Apps $app, Companies $company): void
    {
        $company->set(CustomFieldEnum::INTRAS_AGENCY_ID->value, self::AGENCY_ID);

        new Setup($app, static::$cachedUser, $company)->run();

        $type = ParticipantType::firstOrCreate([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'CONFIRMADO',
        ], ['users_id' => static::$cachedUser->getId()]);
        $type->set(CustomFieldEnum::INTRAS_EVENT_ID->value, self::INSCRIPTION_TYPE_ID);
    }

    /**
     * @param array<int, array{first: string, last: string, position?: string|null, company?: int|null}> $participants keyed by legacy participant id
     */
    protected function seedSipgoVersion(
        int $versionId,
        array $participants,
        int $agencyId = self::AGENCY_ID,
        string $name = 'SESION DE INMERSION ESTRATEGICA EN IA'
    ): void {
        $sipgo = $this->sipgo();
        $now = Carbon::now()->toDateTimeString();
        $eventId = $versionId + 500_000;

        $sipgo->table('events')->insert([
            'id' => $eventId,
            'agencies_id' => $agencyId,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sipgo->table('events_versions')->insert([
            'id' => $versionId,
            'events_id' => $eventId,
            'agencies_id' => $agencyId,
            'name' => $name,
            'version' => 1,
            'price_per_ticket' => 100,
            'currencies_id' => 1,
            'events_versions_statuses_id' => 1,
            'max_capacity' => 25,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sipgo->table('events_versions_dates')->insert([
            'events_versions_id' => $versionId,
            'event_date' => Carbon::now()->addMonth()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);

        foreach ($participants as $legacyId => $participant) {
            $companyId = $participant['company'] ?? null;

            if ($companyId !== null && ! $sipgo->table('companies')->where('id', $companyId)->exists()) {
                $sipgo->table('companies')->insert([
                    'id' => $companyId,
                    'name' => 'EMPRESA ' . $companyId,
                    'updated_at' => $now,
                ]);
            }

            if (! $sipgo->table('participants')->where('id', $legacyId)->exists()) {
                $sipgo->table('participants')->insert([
                    'id' => $legacyId,
                    'companies_id' => $companyId,
                    'first_name' => $participant['first'],
                    'last_name' => $participant['last'],
                    'full_name' => $participant['first'] . ' ' . $participant['last'],
                    'position' => $participant['position'] ?? null,
                    'participants_statuses_id' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $sipgo->table('events_versions_participants')->insert([
                'events_versions_id' => $versionId,
                'participants_id' => $legacyId,
                'inscriptions_types_id' => self::INSCRIPTION_TYPE_ID,
                'investment' => 100,
                'discount' => 0,
                'reserved_tickets' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
