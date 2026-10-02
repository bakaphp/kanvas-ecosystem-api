<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\ParticipantMapper;
use Kanvas\Guild\Customers\Models\People;

class DiffPeopleWithIntrasAction
{
    public function __construct(
        protected People $people,
    ) {
    }

    /**
     * Null when the People is not linked to a SIPGO participant, or SIPGO no longer has that row.
     */
    public function execute(): ?array
    {
        $participantId = (int) $this->people->get(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value);

        if ($participantId <= 0) {
            return null;
        }

        $client = new Client($this->people->app);
        $row = $client->table('participants')->where('id', $participantId)->first();

        if ($row === null) {
            return null;
        }

        $current = PullParticipantsFromIntrasAction::loadParticipantContacts($client, [$participantId])[$participantId] ?? [];

        return ParticipantMapper::changes(
            ParticipantMapper::toIntras($this->people, $current),
            $row,
            $current,
        );
    }
}
