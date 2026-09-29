<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\PeopleType;

class FullImportFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?string $lastSyncAt = null,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): array
    {
        $results = [];

        $this->ensurePeopleTypes();

        $results['lookup'] = new PullLookupDataFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->agencyId,
        )->execute();

        $results['organizations'] = new PullOrganizationsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->lastSyncAt,
            $this->agencyId,
        )->execute();

        $results['participants'] = new PullParticipantsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->lastSyncAt,
            $this->agencyId,
        )->execute();

        $results['facilitators'] = new PullFacilitatorsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->lastSyncAt,
            $this->agencyId,
        )->execute();

        $results['events'] = new PullEventsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->lastSyncAt,
            $this->agencyId,
        )->execute();

        // After events and facilitators — it resolves both sides through their legacy-id maps.
        $results['event_version_facilitators'] = new PullEventVersionFacilitatorsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->agencyId,
        )->execute();

        $results['registrations'] = new PullRegistrationsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->lastSyncAt,
            $this->agencyId,
        )->execute();

        // After organizations — resolves each pool's company through its legacy-id map.
        $results['entitlements'] = new PullEntitlementsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->agencyId,
        )->execute();

        $results['plans'] = new PullPlansFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->agencyId,
        )->execute();

        // After events, participants and registrations — resolves all three by legacy id.
        $results['courtesy_passes'] = new PullCourtesyPassesFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->agencyId,
        )->execute();

        // Last — it stamps provenance onto entities every earlier step created.
        $results['audit_provenance'] = new PullAuditProvenanceFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
        )->execute();

        $results['leads'] = new PullLeadsFromIntrasAction(
            $this->app,
            $this->company,
            $this->user,
            $this->lastSyncAt,
            $this->agencyId,
        )->execute();

        return $results;
    }

    protected function ensurePeopleTypes(): void
    {
        $defaults = [
            ['name' => 'Participant', 'is_default' => true],
            ['name' => 'Facilitator', 'is_default' => false],
            ['name' => 'Contact', 'is_default' => false],
            ['name' => 'Key Contact', 'is_default' => false],
            ['name' => 'Employee', 'is_default' => false],
        ];

        foreach ($defaults as $type) {
            PeopleType::firstOrCreate([
                'name' => $type['name'],
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ], [
                'users_id' => $this->user->getId(),
                'is_default' => $type['is_default'],
            ]);
        }
    }
}
