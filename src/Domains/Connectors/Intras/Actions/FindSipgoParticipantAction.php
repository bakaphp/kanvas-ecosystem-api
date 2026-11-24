<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\SipgoEntityEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;

/**
 * SIPGO participants that look like this People, before proposing to create one: same
 * `identification`, or any Kanvas email in `email_oficina` / `email_personal`. 60% of SIPGO
 * participants carry no identification, so email is the match that usually decides.
 */
class FindSipgoParticipantAction
{
    private const array EMAIL_FIELDS = ['email_oficina', 'email_personal'];

    public function __construct(
        protected People $people,
        protected Client $client,
    ) {
    }

    /**
     * @return list<int>
     */
    public function execute(): array
    {
        $ids = [];
        $identification = Str::trimToNull((string) $this->people->get('identification'));

        if ($identification !== null) {
            $ids = $this->client->table('participants')
                ->where('identification', $identification)
                ->where('is_deleted', 0)
                ->pluck('id')
                ->all();
        }

        $emails = $this->people->contacts()
            ->whereIn('contacts_types_id', [
                ContactType::getByName(ContactTypeEnum::EMAIL->getName())->getId(),
                ContactType::getByName(ContactTypeEnum::SECONDARY_EMAIL->getName())->getId(),
            ])
            ->pluck('value')
            ->map(fn ($value) => mb_strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->all();

        if ($emails !== []) {
            $kind = SipgoEntityEnum::PARTICIPANT;
            $ids = [...$ids, ...$this->client->table($kind->customFieldsTable() . ' as v')
                ->join('custom_fields as cf', 'cf.id', '=', 'v.custom_fields_id')
                ->join($kind->table() . ' as p', 'p.id', '=', 'v.' . $kind->foreignKey())
                ->where('cf.modules_id', $kind->customFieldsModuleId())
                ->whereIn('cf.name', self::EMAIL_FIELDS)
                ->whereIn($this->client->getConnection()->raw('LOWER(TRIM(v.value))'), $emails)
                ->where('p.is_deleted', 0)
                ->pluck('p.id')
                ->all()];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
