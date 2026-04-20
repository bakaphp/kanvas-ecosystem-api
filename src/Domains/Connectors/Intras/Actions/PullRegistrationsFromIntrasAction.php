<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\RegistrationMapper;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Models\People;

class PullRegistrationsFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?string $lastSyncAt = null,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): int
    {
        $client = new Client($this->app);

        $query = $client->table('events_versions_participants')
            ->where('is_deleted', 0);

        if ($this->lastSyncAt !== null) {
            $query->where('updated_at', '>=', $this->lastSyncAt);
        }

        $defaultThemeArea = ThemeArea::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        $count = 0;

        $query->orderBy('id')->chunk(500, function ($rows) use (&$count, $defaultThemeArea) {
            foreach ($rows as $row) {
                $eventVersion = $this->findEventVersionByIntrasId($row->events_versions_id);
                $people = $this->findPeopleByIntrasParticipantId($row->participants_id);

                if (! $eventVersion || ! $people) {
                    continue;
                }

                /** @var Participant $participant */
                $participant = Participant::firstOrCreate([
                    'people_id' => $people->getId(),
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                ], [
                    'users_id' => $this->user->getId(),
                    'theme_area_id' => $defaultThemeArea?->getId() ?? 0,
                ]);

                if ($row->created_at !== null && $participant->wasRecentlyCreated) {
                    $participant->created_at = $row->created_at;
                    $participant->saveQuietly();
                }

                $participantType = $this->findParticipantTypeByIntrasId($row->inscriptions_types_id);
                $mapped = RegistrationMapper::fromIntras($row);

                /** @var EventVersionParticipant $evp */
                $evp = EventVersionParticipant::firstOrCreate([
                    'event_version_id' => $eventVersion->getId(),
                    'participant_id' => $participant->getId(),
                ], [
                    'participant_type_id' => $participantType?->getId(),
                    'ticket_price' => $mapped['ticket_price'],
                    'discount' => $mapped['discount'],
                    'invoice_date' => $mapped['invoice_date'],
                    'metadata' => $mapped['metadata'],
                ]);

                if ($row->created_at !== null && $evp->wasRecentlyCreated) {
                    $evp->created_at = $row->created_at;
                    if ($row->updated_at !== null) {
                        $evp->updated_at = $row->updated_at;
                    }
                    $evp->saveQuietly();
                }

                $count++;
            }
        });

        return $count;
    }

    protected function findEventVersionByIntrasId(?int $intrasId): ?EventVersion
    {
        if ($intrasId === null) {
            return null;
        }

        return EventVersion::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value)->where('value', $intrasId)
            )
            ->first();
    }

    protected function findPeopleByIntrasParticipantId(?int $intrasId): ?People
    {
        if ($intrasId === null) {
            return null;
        }

        return People::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_PARTICIPANT_ID->value)->where('value', $intrasId)
            )
            ->first();
    }

    protected function findParticipantTypeByIntrasId(?int $intrasId): ?ParticipantType
    {
        if ($intrasId === null) {
            return null;
        }

        return ParticipantType::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_EVENT_ID->value)->where('value', $intrasId)
            )
            ->first();
    }
}
