<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\SipgoEntityEnum;
use Kanvas\Connectors\Intras\Mappers\FacilitatorMapper;
use Kanvas\Connectors\Intras\Mappers\OrganizationMapper;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Connectors\Intras\Support\SipgoCatalog;
use Kanvas\Connectors\Intras\Support\SipgoChangeSet;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use stdClass;

class DiffPeopleWithIntrasAction
{
    public function __construct(
        protected People $people,
    ) {
    }

    /**
     * One item per SIPGO row this People is linked to (participant, facilitator, or both), each
     * carrying only what differs. Null when the People is linked to nothing, or a linked row no
     * longer exists. Throws ValidationException when a catalog name cannot be resolved.
     *
     * @return list<array{kind: string, legacy_id: int, changes: array}>|null
     */
    public function execute(): ?array
    {
        $participantId = ParticipantMapper::participantId($this->people);
        $facilitatorId = SipgoEntityEnum::FACILITATOR->legacyIdOf($this->people);

        if ($participantId === null && $facilitatorId === null) {
            return null;
        }

        $client = new Client($this->people->app);
        $catalog = new SipgoCatalog($client);
        $items = [];

        if ($participantId !== null) {
            $row = $client->table('participants')->where('id', $participantId)->first();
            if ($row === null) {
                return null;
            }

            $current = PullParticipantsFromIntrasAction::loadParticipantContacts($client, [$participantId])[$participantId] ?? [];
            $items[] = $this->item(
                SipgoEntityEnum::PARTICIPANT,
                $participantId,
                SipgoChangeSet::build(
                    ParticipantMapper::toIntras($this->people, $current),
                    $row,
                    $current,
                    $catalog,
                ),
            );
        }

        if ($facilitatorId !== null) {
            $row = $client->table('facilitators')->where('id', $facilitatorId)->first();
            if ($row === null) {
                return null;
            }

            $current = PullFacilitatorsFromIntrasAction::loadFacilitatorContacts($client, [$facilitatorId])[$facilitatorId] ?? [];
            $facilitator = Facilitator::query()
                ->where('people_id', $this->people->getId())
                ->fromApp($this->people->app)
                ->fromCompany($this->people->company)
                ->first();
            $items[] = $this->item(
                SipgoEntityEnum::FACILITATOR,
                $facilitatorId,
                SipgoChangeSet::build(
                    FacilitatorMapper::toIntras(
                        $this->people,
                        $facilitator,
                        $current,
                        alsoParticipant: $participantId !== null,
                    ),
                    $row,
                    $current,
                    $catalog,
                ),
            );
        }

        return array_values(array_filter($items));
    }

    /**
     * The item that creates a SIPGO participant for an unlinked People: every value from null. Throws
     * ValidationException when the person's Organization is not in SIPGO or a catalog name fails.
     *
     * @return array{kind: string, legacy_id: null, changes: array}
     */
    public function createItem(): array
    {
        $companyId = $this->people->organizations()->get()
            ->map(fn (Organization $organization) => OrganizationMapper::companyId($organization))
            ->first(fn (?int $id) => $id !== null);

        if ($companyId === null) {
            throw new ValidationException(sprintf(
                'People %d: its organization is not in SIPGO yet; create the organization first.',
                $this->people->getId(),
            ));
        }

        $client = new Client($this->people->app);

        return SipgoEntityEnum::PARTICIPANT->item(null, SipgoChangeSet::build(
            ParticipantMapper::toIntrasForCreate($this->people, $companyId),
            new stdClass(),
            [],
            new SipgoCatalog($client),
        ));
    }

    private function item(SipgoEntityEnum $kind, int $legacyId, array $changes): ?array
    {
        return $changes === [] ? null : $kind->item($legacyId, $changes);
    }
}
