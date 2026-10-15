<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Illuminate\Support\Collection;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Contracts\CrmLeadDriver;
use Kanvas\Guild\Leads\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSync;
use Kanvas\Guild\Leads\DataTransferObject\CrmPeopleMatch;
use Kanvas\Guild\Leads\Enums\CrmPeopleMatchEnum;
use Kanvas\Guild\Leads\Services\NameAndContactPeopleFinder;

/**
 * Finds the people a CRM customer already is in Kanvas: by the CRM's customer id, and failing that by
 * name plus one shared contact.
 */
class FindCrmPeopleAction
{
    public function __construct(
        protected readonly CrmLeadSync $data,
        protected readonly CrmLeadDriver $driver
    ) {
    }

    public function execute(): ?CrmPeopleMatch
    {
        return $this->byClientId() ?? $this->byNameAndContact();
    }

    private function byClientId(): ?CrmPeopleMatch
    {
        $field = $this->driver->clientIdField();

        if ($field === null) {
            return null;
        }

        /** @var People|null $people */
        $people = People::getByCustomFieldBuilderTransactionSafe($field, $this->data->identity->client_id, $this->data->company)
            ->fromApp($this->data->app)
            ->fromCompany($this->data->company)
            ->notDeleted()
            ->orderBy('id')
            ->first();

        return $people === null ? null : new CrmPeopleMatch($people, CrmPeopleMatchEnum::CLIENT_ID);
    }

    /**
     * A person that already carries a different customer id of this CRM is somebody else with the same
     * name and a shared phone, so it is not a match.
     */
    private function byNameAndContact(): ?CrmPeopleMatch
    {
        $customer = $this->data->customer;

        if (! $customer instanceof CrmCustomer) {
            return null;
        }

        $candidates = new NameAndContactPeopleFinder()
            ->find($this->data->app, $this->data->company, $customer)
            ->filter(fn (People $people): bool => $this->isSameCustomer($people));

        return $this->oldest($candidates);
    }

    private function isSameCustomer(People $people): bool
    {
        $field = $this->driver->clientIdField();

        if ($field === null) {
            return true;
        }

        $stored = Str::trimToNull((string) $people->get($field));

        return $stored === null || $stored === $this->data->identity->client_id;
    }

    /**
     * @param Collection<int, People> $candidates already ordered by id
     */
    private function oldest(Collection $candidates): ?CrmPeopleMatch
    {
        $people = $candidates->first();

        if (! $people instanceof People) {
            return null;
        }

        return new CrmPeopleMatch($people, CrmPeopleMatchEnum::NAME_AND_CONTACT, $candidates->count() > 1);
    }
}
